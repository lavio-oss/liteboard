# Telemetry service và các luồng liên quan — trạng thái hiện tại

> Đối chiếu code và contract trong repository ngày 2026-10-18. Tài liệu này mô tả implementation hiện tại, không xác nhận môi trường pilot hay production. Tài liệu IT-1 đến IT-5 cũ được thay bằng file này; Phase 6 (giao event cho EAMO qua hàng đợi) vẫn để riêng ở [phase-06-eamo-integration.md](../plan/phase-06-eamo-integration.md).

## 1. Sơ đồ tổng thể

    Control Plane (Laravel)                         Data Plane (Python)
    Tenant → Project → Device → optional Profile    MQTT TLS subscriber
           → Access Token / broker ACL              topic + DB scope resolution
           → REST, policies, stream tickets          decode → dedup → validate/skip
                      │                              PostgreSQL transaction
                      │ REST / signed ticket          │ commit
                      ▼                              ├─ telemetry.sample / outcome
            Nuxt web (web/) ◀── WebSocket ───────────└─ device.presence
              snapshot + recovery

Laravel sở hữu cấu hình, tenant/project scope, user authorization và REST. Python telemetry service nhận message từ MQTT, xác thực scope bằng dữ liệu đã resolve, ghi PostgreSQL và phát event sau khi lưu. Frontend dùng REST để lấy snapshot/lịch sử và WebSocket để cập nhật trong lúc đang mở trang.

Rule, alert và webhook (IT-5, mục 12) chạy trên dữ liệu đã commit:

    Ingest transaction ──▶ telemetry_evaluation_jobs
                                     │
              Rule evaluator (Python, task nền, 1 transaction / Device)
                                     ▼
       rule_evaluation_states · alerts · alert_events · outbox_events
                                     │ commit
        php artisan webhooks:dispatch (Laravel) ──▶ webhook endpoint (HTTPS, ký HMAC)
        Presence monitor (Device offline) ──▶ đóng alert đang mở, cùng cơ chế outbox

## 2. Ranh giới dữ liệu và quyền

- Tenant là ranh giới cô lập. Project, Device, Equipment/Asset, Device Profile và telemetry của thiết bị thuộc Tenant qua quan hệ Project.
- project_id và device_id trong MQTT topic chỉ giúp định tuyến message tới bản ghi cần resolve; chúng không cấp quyền. Telemetry service xác nhận cặp Project/Device và trạng thái Device, Project, Tenant trong PostgreSQL.
- Telemetry sample lưu project_id và device_id. Tenant được suy ra từ Device → Project → Tenant; không lấy tenant_id từ firmware hoặc payload.
- Profile và version thuộc Project. Device có thể chưa gán profile. Khi đã gán, version được dùng để validate là version phía server đang gắn với Device; client không được tự chọn profile của Project khác.
- Device là định danh MQTT, tách biệt với Equipment/Asset; Device có thể liên kết tùy chọn tới Equipment Asset trong cùng Project. Không có bảng Motor riêng trong flow này.
- Control Plane có CRUD Device Profile và tạo version theo Project. Version đã tạo là bất biến/append-only; chỉnh schema tạo version mới. Device Profile Version chưa bị backfill vào các sample đã lưu.
- REST cho telemetry dùng Sanctum, tenant.context, read ability, scoped route binding và Device policy. Presence ticket kiểm tra viewTenantDevices. API unresolved trung tâm chỉ dành cho platform admin vì topic chưa resolve có thể chứa ID không đáng tin cậy.
- Rule, alert, alert event, outbox event, webhook endpoint và delivery là dữ liệu tenant-owned: mọi bảng có project_id với FK cascade, tenant suy ra qua Project. Worker xử lý nhiều tenant nhưng mọi truy vấn gắn với project_id của dòng đang xử lý; webhook endpoint chỉ nhận event cùng Project.
- Device credential và broker ACL giới hạn publish vào topic của chính Device. Các status active/approval/credential là lifecycle/authorization state riêng, không thay thế kết quả kết nối hay ingest.

## 3. Setup thiết bị và topic MQTT

Device dùng Access Token hiện hành để xác thực MQTT qua broker TLS. Các topic mà credential cấp gồm:

    liteboard/{project_id}/devices/{device_id}/telemetry
    liteboard/{project_id}/devices/{device_id}/heartbeat

Telemetry service subscribe wildcard cho hai channel ở QoS 1. Topic phải có đúng 5 đoạn, đúng prefix/resource/channel, và Project/Device phải là UUID. Topic sai được ghi vào central telemetry_unresolved_messages với topic đã giới hạn độ dài, payload size và reason; không lưu raw payload.

## 4. Luồng ingest từng message

### 4.1 Telemetry

1. MQTT callback ghi thời điểm nhận ở service, parse topic, rồi gửi telemetry tới PostgresTelemetryIngestor.
2. Một PostgreSQL transaction resolve Device theo đúng device_id + project_id, join Project/Tenant, đọc profile đang gắn và khóa các bản ghi scope để trạng thái không đổi giữa resolve và update.
3. Nếu Device đang active, approved, Project và Tenant đều active, service cập nhật last_seen_at, connection_state=online và lấy received_at từ transaction. Việc này diễn ra trước khi payload/schema bị reject: telemetry có topic hợp lệ từ Device đã resolve vẫn là tín hiệu sống, kể cả message retained hoặc schema sai.
4. Service kiểm tra payload tối đa 64 KiB, JSON UTF-8 hợp lệ, object ở lớp ngoài, không có khóa JSON trùng và không có số JSON không hữu hạn. Sau đó chọn contract theo việc Device có device_profile_version_id hay không.
5. Telemetry retained bị ghi outcome rejected với reason retained_message; không tạo sample.
6. Nếu có message_id và hash nội dung, service khóa theo Device/ID rồi tra identity hiện có. Cùng ID/cùng hash là duplicate; cùng ID/khác hash là idempotency_conflict. Kiểm tra này diễn ra trước profile schema validation.
7. Với profile, service validate envelope và schema version được gán. Không có profile thì service validate contract cơ bản rồi thêm metadata còn thiếu; chỉ bước schema validation theo Profile mới được bỏ qua.
8. Với message được chấp nhận, cùng transaction ghi sample, cập nhật per-metric latest values, ghi message identity và outcome persisted. Rejection/duplicate/conflict cũng có outcome riêng nhưng không tạo sample mới.
9. Nếu Device đã gắn Profile Version và Profile đó có alert rule đang active, cùng transaction còn ghi một job vào telemetry_evaluation_jobs (kèm Equipment mà Device đang liên kết, mục 12). Khi transaction commit xong, callback trả MQTT subscriber PUBACK success. Sau đó service phát WebSocket event best-effort. Nếu xử lý/ghi PostgreSQL thất bại trong flow này, callback lỗi, service lên lịch reconnect và không chủ động ACK message đó.

### 4.2 Heartbeat

Heartbeat đi qua pipeline ngắn hơn: parse topic → decode JSON object → cập nhật presence cho Device hợp lệ → phát device.presence. Heartbeat không tạo telemetry sample, latest value hay ingest outcome. JSON heartbeat lỗi không cập nhật presence. Cờ retained chỉ được áp dụng để reject telemetry; không làm thay đổi pipeline heartbeat.

### 4.3 Không resolve được scope

- Topic không đúng cấu trúc/UUID: central record với invalid_topic.
- Device không tồn tại hoặc topic Project không khớp Project của Device: central record với device_not_found hoặc project_mismatch.
- Device tồn tại đúng Project nhưng không được nhận ingest do chưa approved/inactive, hoặc Project/Tenant inactive: ghi outcome unresolved trong scope Device/Project đã xác nhận, với reason tương ứng. Các message này không cập nhật presence.
- Lỗi lưu central record cho topic không hợp lệ hiện được log rồi ACK; không có sample nào bị ảnh hưởng vì topic không được nhận diện là telemetry hợp lệ.

## 5. Contract payload

### 5.1 Device đã gắn Profile Version

Envelope cần đủ bốn trường:

    {
      "message_id": "device-message-id",
      "schema_version": 1,
      "occurred_at": "2026-10-16T10:00:00Z",
      "values": {
        "temperature": 25.12,
        "humidity": 60
      }
    }

- message_id: chuỗi không rỗng, tối đa 128 ký tự.
- schema_version: số nguyên dương và phải bằng version phía server gắn với Device.
- occurred_at: ISO 8601 có timezone. Từ chối thời điểm cách server hơn 5 phút về tương lai; dữ liệu đến trễ vẫn được lưu.
- values: object JSON. Từng field được kiểm theo schema: type, required, strict mode, min/max và allowed values. Type được hỗ trợ trong validator là integer, float, string và boolean; boolean không được coi là integer. Unit là metadata schema; ingress hiện không tự đổi đơn vị.
- Payload quá 64 KiB, envelope thiếu/sai metadata, version không khớp, hoặc metric không qua schema sẽ không tạo sample.

### 5.2 Device chưa gắn Profile Version

Không cần trường values. Hai dạng sau đều hợp lệ:

    {"temperature":25.12,"humidity":60}

    {"values":{"temperature":25.12,"humidity":60}}

- Nếu có values, nó phải là object không rỗng và là nguồn metric duy nhất; không được trộn metric cấp ngoài với object này.
- Nếu không có values, metric lấy từ key/value cấp ngoài sau khi loại message_id, occurred_at, schema_version.
- Metric hỗ trợ number hữu hạn, string, boolean. Object, array và null làm value bị reject. Payload phải có ít nhất một metric.
- message_id vắng mặt thì server cấp UUID; nếu firmware gửi thì vẫn cần chuỗi không rỗng, tối đa 128 ký tự.
- occurred_at vắng mặt thì server dùng received_at; nếu được gửi thì cần ISO 8601 có timezone và không quá 5 phút trong tương lai.
- schema_version là metadata dành riêng: có thể vắng mặt; nếu có phải là số nguyên dương, nhưng không chọn schema nào.
- Metadata có mặt nhưng null hoặc sai kiểu bị reject. Việc nhận dạng flat/wrapped payload và chuẩn hóa về values chỉ diễn ra ở ingress boundary.
- Thành công được ghi persisted, reason_codes=[], device_profile_version_id=null, và trace có schema_validation_skipped. Đây là bỏ qua profile schema, không phải bỏ qua lưu trữ hay mọi kiểm tra payload.

### 5.3 Deduplication hiện tại

- Hash được tính từ JSON đầu vào đã canonicalize (sort key, bỏ khoảng trắng định dạng) trước khi metadata do server cấp được thêm.
- Dedup dùng (device_id, message_id). Cùng ID và nội dung canonical giống nhau trả duplicate; cùng ID nhưng nội dung khác trả idempotency_conflict.
- Chỉ message có message_id từ client có retry identity ổn định. Nếu thiếu ID, server sinh UUID riêng cho mỗi lần nhận; publish lại sẽ là message mới, không có cam kết dedup.
- Message bị reject không ghi message identity. Bảng identity hiện khóa ngoại tới sample; xóa sample sẽ cascade identity. Chưa có retention job hoặc thời hạn dedup độc lập được cấu hình.

## 6. Persistence và kết quả ingest

Các bảng liên quan hiện tại:

| Bảng | Vai trò |
| --- | --- |
| telemetry_samples | Sample đã persist, values JSONB, occurred_at, received_at, message ID và Profile Version ID nullable. |
| telemetry_latest_values | Một giá trị mới nhất cho từng (device_id, metric); so thứ tự (occurred_at, received_at, sample_id) để sample trễ không làm lùi latest. Không phụ thuộc FK sample để giữ latest khi sample retention được thiết kế sau này. |
| telemetry_message_identities | Hash và ID dùng cho dedup của message đã persist. |
| telemetry_ingest_outcomes | Kết quả, reason_codes, processing_id và các stage timestamp; không lưu raw payload. |
| telemetry_unresolved_messages | Central trace cho topic/ID chưa resolve; chỉ lưu routing metadata và kích thước payload, không lưu raw payload. |
| devices | connection_state, last_seen_at và updated_at, thuộc resource Device hiện có. |
| telemetry_evaluation_jobs | Hàng đợi đánh giá rule: sample_id (unique), Device, Profile Version, equipment_asset_id tại thời điểm sample, received_at, evaluated_at (null = chưa xử lý). |
| alert_rules, alert_rule_revisions | Rule theo Project + Profile và các revision bất biến (definition JSONB). |
| rule_evaluation_states | Trạng thái cửa sổ theo (device_id, alert_rule_id): trigger_since, clear_since, con trỏ sample cuối. |
| alerts, alert_events | Alert (active/cleared và acknowledged là hai trục riêng) và lịch sử chuyển trạng thái. |
| outbox_events | Event bền vững chờ giao ra ngoài; idempotency_key unique. |
| webhook_endpoints, webhook_deliveries, webhook_delivery_attempts | Endpoint (secret mã hóa), từng lần giao cho mỗi endpoint và lịch sử từng lần thử. |

Migration 2026_10_15_174624_allow_profileless_telemetry_samples cho phép telemetry_samples.device_profile_version_id là null; các FK scope Project/Device và FK Profile Version có giá trị vẫn giữ nguyên. Profile được gắn sau này không backfill hoặc sửa sample cũ.

Một transaction xử lý sample thường bao gồm presence update, sample, latest values, dedup identity và ingest outcome. Rejected/duplicate/conflict chỉ ghi những bản ghi phù hợp với kết quả, không ghi sample. Tenant không được materialize vào sample; quan hệ tenant được truy qua Project. Không có raw MQTT payload trong outcome hay WebSocket.

**Giới hạn cần biết:** latest table lưu metric dạng tối đa 64 ký tự, trong khi nhánh chưa profile hiện không kiểm tra độ dài key metric tại ingress. Key vượt giới hạn làm PostgreSQL transaction lỗi và không tạo sample; chưa có reason code validation riêng cho trường hợp này. Aggregates chỉ chạy trên PostgreSQL và chỉ tính metric JSON number; string/boolean vẫn có thể xem ở latest/history.

## 7. Device presence và trạng thái realtime

Device presence, browser stream và ingest outcome là ba trạng thái khác nhau:

| Trạng thái | Ý nghĩa |
| --- | --- |
| Device online / unstable / offline | Tuổi của tín hiệu cuối cùng từ MQTT. online nếu không quá 30 giây; unstable trên 30 đến hết 90 giây; offline trên 90 giây hoặc chưa từng có last_seen_at. |
| Stream trình duyệt connecting / live / reconnecting / forbidden / stopped | Trạng thái kết nối WebSocket phía web. Đây là state trong composable, không đổi devices.connection_state. |
| Ingest persisted / rejected / duplicate / idempotency_conflict / unresolved | Kết quả riêng của một message. Device vẫn có thể online khi message bị reject. skipped chỉ là outcome lịch sử của hành vi cũ. |

Telemetry có topic hợp lệ từ Device đang hoạt động cập nhật last_seen_at trước validation; heartbeat JSON hợp lệ cũng cập nhật. Presence monitor refresh khi service khởi động rồi mỗi 5 giây (khi Device chuyển offline, cùng transaction đóng các alert đang mở của Device, mục 12.4), dùng last_seen_at để chuyển online → unstable → offline; browser WebSocket disconnect không tác động trạng thái thiết bị. Một sample tới muộn hoặc bị reject không làm latest telemetry lùi, dù vẫn có thể chứng minh Device vừa gửi tín hiệu.

## 8. REST và WebSocket

### 8.1 REST routes

Các device routes đều tenant/project/device scoped, có read ability và Device policy:

| Route | Kết quả |
| --- | --- |
| GET /api/tenants/{tenant}/projects/{project}/devices/{device}/telemetry/latest | Mỗi metric một latest value, kèm sample/message ID và timestamp. |
| GET .../devices/{device}/telemetry?from&to&metrics[]&limit&cursor | Lịch sử sample trên khoảng [from,to), keyset cursor theo (occurred_at,id), tối đa 31 ngày, 1–1000 sample/trang, tối đa 20 metric filter. |
| GET .../devices/{device}/telemetry/aggregates?from&to&interval&metrics[] | avg/min/max/count theo bucket; interval 1m, 5m, 15m, 1h, 1d; tối đa 1000 bucket. |
| GET .../devices/{device}/messages?limit | Recent ingest outcomes; limit API tối đa 200. |
| GET .../devices/{device}/messages/{message_id_or_processing_id}/trace | Tất cả outcome của message và sample nếu message đã được persist; không trả raw payload. |
| POST .../devices/{device}/telemetry/stream-ticket | Ticket đọc WebSocket của đúng Project/Device. |
| POST /api/tenants/{tenant}/devices/presence-stream-ticket | Ticket đọc presence stream cho Tenant mà user được phép xem. |
| GET /api/platform/telemetry/unresolved-messages[/{processing_id}] | Central unresolved records; chỉ platform admin. |

History yêu cầu from/to có timezone; timestamp response dùng UTC. Aggregation chỉ cast JSON number, không tự chuyển string numeric sang number.

### 8.1b REST routes cho rule, alert và webhook

Tất cả nằm dưới /api/tenants/{tenant}/projects/{project}, có tenant.context, scoped binding và ability control-plane:read (GET) hoặc control-plane:write (POST/PATCH). Body không được chứa project_id/tenant_id (prohibited).

| Route | Kết quả | Quyền |
| --- | --- | --- |
| GET/POST /alert-rules, GET /alert-rules/{rule} | Danh sách; tạo rule kèm revision 1; chi tiết kèm revisions. | Xem: thành viên Project. Ghi: platform admin, tenant Owner/Admin, project Admin/Engineer. |
| POST /alert-rules/{rule}/revisions | Revision mới (409 nếu rule đã archived). | Như ghi rule. |
| POST /alert-rules/{rule}/archive | Archive rule, đóng alert đang mở (`rule_archived`). Gọi lại không đổi gì. | Như ghi rule. |
| GET /alerts?state&acknowledged&device_id&alert_rule_id&limit&cursor | Alert mới nhất trước, cursor pagination (tối đa 100/trang). | Thành viên Project. |
| GET /alerts/{alert} | Alert kèm lịch sử sự kiện theo thời gian. | Thành viên Project. |
| POST /alerts/{alert}/acknowledge | Ghi người và thời điểm xác nhận; idempotent; không đóng alert. | platform admin, tenant Owner/Admin, project Admin/Engineer. |
| GET/POST /webhook-endpoints, PATCH /webhook-endpoints/{endpoint} | Danh sách, tạo (trả secret một lần), sửa url/events/enabled. Không có delete. | Xem: project Admin/Engineer. Tạo/sửa: project Admin (hoặc tenant Owner/Admin). |
| POST /webhook-endpoints/{endpoint}/rotate-secret | Secret mới, trả một lần. | Project Admin. |
| GET /webhook-endpoints/{endpoint}/deliveries?status&limit&cursor | Từng lần giao kèm lịch sử các lần thử. | Project Admin/Engineer. |
| POST /webhook-endpoints/{endpoint}/deliveries/{delivery}/redeliver | Đặt lại delivery `failed`/`exhausted` để gửi lại với cùng event id (409 nếu trạng thái khác). | Project Admin. |

### 8.2 Hai WebSocket streams

Ticket được Laravel ký HMAC-SHA256 và có TTL 60 giây. Client xin ticket mới qua API mỗi 40 giây; việc gia hạn lại chạy qua authentication, tenant context và policy hiện hành. Telemetry service kiểm tra signature, expiry và identity claims; stream đóng nếu ticket hết hạn hoặc renewal không cùng user/scope. WebSocket bị từ chối nếu TELEMETRY_WS_SECRET/shared secret không được cấu hình đủ độ dài; MQTT ingest vẫn có thể hoạt động.

| Stream | Scope fan-out | Events |
| --- | --- | --- |
| /ws/telemetry?ticket=... | Một (project_id, device_id) | telemetry.sample, telemetry.outcome; cùng shape cốt lõi với REST, không có raw payload. |
| /ws/device-presence?ticket=... | Một Tenant | device.presence gồm project_id, device_id, connection_state, last_seen_at, updated_at. |

Service phát sample/outcome sau khi DB commit. Presence event cũng được phát sau khi presence update commit. Ghi stage broadcast là best-effort. Mỗi hub hiện chạy in-process, tối đa 500 kết nối theo cấu hình, queue 256 event mỗi subscriber; subscriber chậm bị ngắt với code 4008. Đây chưa phải fan-out dùng chung giữa nhiều telemetry-service instance. Event có thể mất trong lúc web disconnect nên REST snapshot/history/outcomes là đường recovery.

## 9. Frontend hiện tại

Frontend ứng dụng nằm trong web/:

- /devices tải Device snapshot trong tenant đang chọn, hiển thị trạng thái connection cùng last_seen_at; approval/profile là các field riêng. Khi presence WebSocket mở hoặc reconnect, trang tải lại snapshot. Presence event chỉ áp dụng nếu khớp Device/Project và updated_at mới hơn snapshot; khi đổi tenant, event cache bị xóa.
- /device-profiles (thuộc mục Rules & schemas) là màn quản lý profile/schema theo Project; tạo version mới và gán version vào Device đi qua Control Plane API.
- /devices/{projectId}/{deviceId} có Overview, Telemetry, Alerts, Message Inspector và Settings. Tab Alerts liệt kê alert của riêng Device (lọc theo device_id), kèm số alert đang active trên tab, mở chi tiết và Acknowledge cho người có quyền ghi; tab Settings (chỉ người có quyền ghi) sửa thông tin Device. Overview lấy Device và latest values qua REST. Telemetry lấy history/aggregates qua REST rồi ghép sample realtime. Late sample không ghi đè latest theo quy tắc event-time.
- Message Inspector tải recent outcomes, lọc theo message ID hoặc processing ID, hiển thị status và một reason code tóm tắt; click View trace gọi REST rồi mở drawer với reason chi tiết, processing stages, thời gian, và sample nếu có.
- Drawer hiển thị **“No validation errors.”** khi reason_codes rỗng (bao gồm message profile-less mới được persist); outcome lịch sử skipped + schema_not_assigned cũng được coi là không có lỗi validation. Stage schema_validation_skipped chỉ nói profile schema không chạy.
- Browser parse event bằng Zod; sample merge theo id, outcome merge theo processing_id. Khi stream reconnect, frontend tải REST history/outcomes để bù gap. Presence stream tải lại device snapshot. Tenant đổi thì state thuộc tenant cũ được xóa.
- Stream status chỉ được giữ trong composable cho reconnect/permission handling. Component badge “Presence: Live” hoặc “Telemetry: Live” không nằm trong trang hiện tại; trạng thái hiển thị cho người dùng là trạng thái kết nối Device và outcome từng message.

- Alerts và Rules & schemas ở sidebar. /alerts (Alert log) là màn theo dõi: chọn một Project, bảng AdminTable có tìm kiếm, Display, reload và phân trang, modal chi tiết (evidence, lịch sử) và Acknowledge. Mục Rules & schemas gom ba màn cấu hình dùng chung thanh menu Device profiles / Alert rules / Webhooks: /device-profiles, /alert-rules (tạo rule và revision bằng modal hai tab General / Condition, archive có xác nhận) và /webhooks (tạo/sửa/bật tắt endpoint, rotate secret hiện một lần có nút copy, lịch sử giao và Redeliver; chỉ project Admin/Engineer thấy dữ liệu, chỉ Admin thay đổi được).

API calls đi qua useApi/useTelemetry/useAlerting, không gọi REST trực tiếp từ table/drawer/modal component.

## 10. Lỗi, ACK và readiness

- MQTT client mặc định là liteboard-telemetry-service, dùng persistent MQTT v5 session, clean_session=False, optimistic_acknowledgement=False; receive maximum mặc định 20.
- Với telemetry đã nhận diện, ACK chỉ trả sau transaction thành công. Lỗi PostgreSQL làm callback ném lỗi và kích hoạt reconnect; QoS 1 cùng identity giúp retry của client có message_id không tạo sample logic thứ hai.
- PUBACK mà firmware nhận từ broker chỉ chứng minh broker đã nhận publish; nó không tự chứng minh rằng PostgreSQL đã commit.
- /health chỉ báo process đang chạy. /ready kiểm tra cả PostgreSQL và MQTT subscriber; lỗi một trong hai trả 503.
- WebSocket delivery là best-effort; sample/outcome bền vững trong REST/DB là nguồn recovery. Browser reconnect không làm phát lại message MQTT.

## 11. Bằng chứng và giới hạn hiện tại

Các spec trước có ghi lại kết quả local, được giữ ở đây để không mất bối cảnh; chúng không được chạy lại trong lần gom tài liệu này:

- IT-3 ghi L0 local: 10 Device × 1 msg/s × 5 phút, 3.000/3.000 persisted, không có reject/duplicate/conflict/failure; transaction p50/p95/p99 11.189/24.836/43.171 ms. Recovery probe với lỗi storage giả lập commit lại sau 6.053 giây. Đây là PostgreSQL 16 + Mosquitto cô lập local, không phải capacity/SLO pilot.
- IT-4 ghi 39/39 check E2E local và tải 300 message ở 10 msg/s không mất sample; publish→WebSocket p50/p95/p99 15,8/37,0/108,2 ms; received→commit 9,2/20,5/43,6 ms; received→broadcast 13,2/28,7/86,9 ms. Ghi nhận test đó đề ngày 2026-10-13.
- Follow-up ngày 2026-10-15 ghi Python ingest/subscriber tests, frontend telemetry contract tests và typecheck pass; browser E2E cho Message Inspector realtime chưa chạy.
- IT-5 ghi nhận ngày 2026-10-18: backend `composer stan` sạch và 269 test pass, telemetry-service 148 test pass (gồm integration trên PostgreSQL thật cho evaluator), frontend typecheck và 40 test pass. Chưa chạy E2E với simulator, broker MQTT và webhook receiver; chưa đo evaluation latency, tuổi outbox hay tỉ lệ giao webhook trên tải thật (dùng `php artisan alerting:stats` khi chạy).
- Chưa có số liệu pilot/production, browser acceptance mới nhất, hoặc chứng minh multi-instance WebSocket fan-out. Các phép đo local ở trên không thay thế những cổng này.
- telemetry-service/tests/ có unit, PostgreSQL integration và MQTT integration coverage; test_postgres_integration.py/test_mqtt_integration.py cần dịch vụ tích hợp tương ứng. Các kết quả lịch sử cần được chạy lại trên commit/môi trường cần nghiệm thu.

## 12. Rule, alert và webhook (IT-5)

### 12.1 Luồng

1. Ingest ghi sample như mục 4. Nếu Device có Profile Version và Profile có rule active, cùng transaction ghi thêm telemetry_evaluation_jobs. Device chưa gắn Profile không bị đánh giá.
2. Rule evaluator (task nền trong telemetry-service, poll mặc định 1 giây, biến RULE_EVALUATION_POLL_SECONDS) chọn một Device có job chờ, lấy advisory lock theo Device (không chờ), đọc tối đa 500 job theo (received_at, sample_id) và đánh giá từng rule. Một transaction cập nhật rule_evaluation_states, mở/cập nhật/đóng alert, ghi alert_events, ghi outbox_events (ON CONFLICT trên idempotency_key) rồi đánh dấu job đã xử lý. Lỗi giữa chừng rollback toàn bộ; job vẫn chờ và lần sau cho cùng kết quả.
3. `php artisan webhooks:dispatch` (service webhook-dispatcher trong docker-compose) chuyển outbox_events thành webhook_deliveries cho mỗi endpoint enabled cùng Project có đăng ký loại event, rồi gửi HTTP ngoài transaction. HTTP chậm hoặc lỗi không rollback telemetry và không làm mất event.
4. Thứ tự khóa để tránh deadlock: rule (`FOR SHARE` ở evaluator, `FOR UPDATE` khi archive) → rule_evaluation_states → alerts.

### 12.2 Định nghĩa rule

Rule thuộc Project + Device Profile, mã (code) duy nhất trong Project. Mỗi lần sửa tạo một revision bất biến; evaluation, alert và event ghi revision đã áp dụng. Các giá trị do user khai báo trên từng rule; `time_basis` cố định là server `received_at`. Ví dụ rule pilot:

    {
      "field": "temperature",
      "trigger": { "operator": "gt", "value": 80, "duration_seconds": 30 },
      "clear":   { "operator": "lte", "value": 75, "duration_seconds": 30 },
      "max_sample_gap_seconds": 15,
      "severity": "critical"
    }

Validation ở API (và được web kiểm tra trước khi gửi): field là kiểu integer/float trong version mới nhất của Profile; operator thuộc gt, gte, lt, lte; operator clear ngược chiều trigger và vùng recovery không chồng vùng trigger (`gt 80` thì `clear.value` ≤ 80; nên thấp hơn để có hysteresis); duration trigger và clear trong 5s–1h; max_sample_gap 5s–10m và không lớn hơn trigger duration; severity thuộc critical, major, minor, warning. Gap nên lớn hơn chu kỳ gửi của thiết bị, nếu không rule không bao giờ kích hoạt. Runtime chỉ hỗ trợ so sánh scalar trên một field số; không chạy mã tùy ý của tenant.

### 12.3 Semantics đánh giá

Với mỗi sample theo thứ tự (received_at, sample_id) của một cặp Device + rule:

1. Sample có (received_at, sample_id) không lớn hơn con trỏ của trạng thái là sample trễ hoặc trùng: bỏ qua, không đổi trạng thái. Nhờ vậy replay, retry và job trùng cho cùng kết quả.
2. Revision mới của rule reset cả hai cửa sổ.
3. Khoảng cách giữa hai sample lớn hơn max_sample_gap reset cửa sổ. Field thiếu, không phải số hoặc là boolean cũng reset cửa sổ (con trỏ vẫn tiến).
4. Chưa có alert active: điều kiện trigger giữ liên tục đủ duration thì mở alert; ngược lại cửa sổ trigger bị xóa.
5. Đang có alert active: sample vẫn thỏa trigger chỉ cập nhật last_seen_at; điều kiện clear giữ liên tục đủ clear duration thì đóng alert (`condition_cleared`); giá trị nằm giữa hai ngưỡng (hysteresis) không đóng và không giữ cửa sổ clear.
6. Revision mới đổi severity khi alert đang mở thì ghi `severity_changed`.

Đánh giá chỉ chạy khi có sample tới, không có timer. Hạn chế biết trước: hai transaction ingest của cùng Device có thể commit lệch thứ tự received_at vài ms; sample commit sau bị coi là trễ và bị bỏ qua, nhưng kết quả vẫn xác định.

### 12.4 Vòng đời alert

Mỗi cặp Device + rule chỉ có tối đa một alert active (partial unique index trên PostgreSQL). Alert lưu opened/last_seen/cleared/acknowledged timestamps, severity, rule revision, Profile Version, sample/message mở và đóng, evidence giới hạn, và equipment_asset_id tại thời điểm mở (Device đổi Equipment sau đó không sửa alert cũ). Active/cleared và acknowledged/unacknowledged là hai trục độc lập.

| Chuyển trạng thái | Nguyên nhân | Ghi chú |
| --- | --- | --- |
| Mở | Điều kiện trigger giữ đủ duration | Event `alert.opened`. |
| Đóng `condition_cleared` | Điều kiện clear giữ đủ duration | Event `alert.cleared`. |
| Đóng `device_offline` | Presence monitor chuyển Device sang offline (không tín hiệu hơn 90 giây) | Cùng transaction reset cửa sổ rule và đặt con trỏ về hiện tại, nên job tồn đọng không mở lại alert. Online lại phải đủ duration mới mở alert mới. |
| Đóng `rule_archived` | Rule bị archive | Xóa evaluation state của rule. |
| Acknowledge | User có quyền ghi | Ghi người và thời điểm, event `alert.acknowledged`; không đóng alert; lặp lại không ghi thêm. |

Không có clear thủ công. Rule đã archive không khôi phục và không xóa được (giữ lịch sử alert và revision); mã của rule archived không dùng lại được trong cùng Project.

### 12.5 Outbox và webhook

Mọi chuyển trạng thái alert ghi outbox_events cùng transaction, idempotency_key dạng `alert:{id}:{loại}` (`severity_changed` kèm revision). Payload v1, do cả Python và Laravel sinh ra với cùng tập key:

    id, type (alert.opened | alert.cleared | alert.acknowledged | alert.severity_changed),
    occurred_at, tenant_id, project_id,
    alert { id, rule_id, rule_code, rule_revision, severity, device_id, equipment_asset_id,
            opened_at, cleared_at, clear_reason, acknowledged_at }

Request gửi đi: POST JSON với header X-LiteBoard-Event-Id, X-LiteBoard-Event-Type, X-LiteBoard-Delivery-Id, X-LiteBoard-Timestamp và X-LiteBoard-Signature: `v1=` + hex HMAC-SHA256 của `"{timestamp}.{body}"` bằng secret của endpoint. Receiver nên khử trùng theo event id.

| Delivery | Ý nghĩa |
| --- | --- |
| pending | Chờ gửi hoặc chờ retry (next_attempt_at). |
| in_flight | Dispatcher đang gửi (lease 60 giây; worker chết thì được nhận lại). |
| succeeded | HTTP 2xx: receiver đã nhận request, không đồng nghĩa nghiệp vụ phía họ xong. |
| failed | Lỗi không retry: 3xx, 4xx khác 408/429, endpoint disabled, đích bị chặn. |
| exhausted | Lỗi tạm thời (408, 429, 5xx, timeout, lỗi kết nối) sau 8 lần thử; backoff 30s, 1m, 5m, 15m, 1h, 6h, 6h. |

Mỗi lần thử ghi webhook_delivery_attempts (mã HTTP, lỗi đã cắt 500 ký tự không chứa header/secret, thời lượng). Project Admin có thể Redeliver delivery failed/exhausted: attempts đặt lại về 0, event id giữ nguyên, lịch sử cũ giữ nguyên. Cấu hình ở backend/config/webhooks.php.

### 12.6 Bảo mật webhook

- Endpoint thuộc Project; secret (`whsec_…`) lưu mã hóa, không nằm trong resource, log hay lịch sử; chỉ trả một lần khi tạo hoặc rotate; rotate có hiệu lực ngay cho lần gửi sau. Phải dùng cùng APP_KEY giữa backend và webhook-dispatcher.
- Chống SSRF: chỉ https, không userinfo/fragment, cổng 443; resolve mọi A/AAAA và từ chối nếu có địa chỉ riêng (private, loopback, link-local gồm 169.254.169.254, CGNAT, unique-local IPv6, IPv4-mapped, multicast); không theo redirect; kiểm tra khi lưu endpoint và kiểm tra lại ngay trước mỗi lần gửi, rồi ghim kết nối vào địa chỉ đã kiểm (chống DNS rebinding). Chỉ cho http/đích riêng khi `WEBHOOKS_ALLOW_PRIVATE_DESTINATIONS=true` (môi trường dev với mock receiver).

### 12.7 Vận hành và số đo

- Chạy migration `2026_10_18_000001_create_alerting_tables` trước khi chạy telemetry-service mới; service dùng các bảng này khi ingest.
- Cần hai tiến trình dài hạn ngoài API: evaluator (chạy cùng telemetry-service) và `php artisan webhooks:dispatch`. Thiếu evaluator thì job tích lại và không có alert; khi chạy lại sẽ xử lý phần tồn đọng mà không tạo alert trùng.
- `php artisan alerting:stats` in evaluation latency p50/p95, số job chờ và tuổi job cũ nhất, tuổi outbox chưa fan-out, số delivery theo trạng thái, số delivery đã retry và đã hồi phục.

### 12.8 Tiêu chí nghiệm thu pilot (chưa chạy E2E)

- Simulator gửi nhiệt độ trên 80 °C liên tục theo duration và gap tạo đúng một alert `critical` trên Device/Equipment tương ứng.
- Duplicate message, evaluation retry, replay và worker restart không nhân đôi alert hoặc outbox event.
- Mẫu thiếu, gap dài, dữ liệu trễ, hysteresis và revision mới cho kết quả xác định; acknowledge không đánh dấu recovery; Device offline đóng alert đúng một lần.
- Webhook chậm, timeout, 5xx, duplicate delivery và retry exhaustion lưu lịch sử; worker khởi động lại gửi tiếp mà không mất event.
- User khác Tenant/Project không tạo, xem hay acknowledge rule/alert/endpoint ngoài scope.

## 13. Việc chưa thuộc flow hiện tại

- Nghiệm thu E2E của IT-5 (mục 12.8) và đo tải chưa chạy. Rule chỉ so sánh scalar trên một field số (chưa có `==`/`!=`, boolean/chuỗi, nhiều field); chưa có timer riêng cho Device ngừng gửi nhưng vẫn online (alert chỉ đóng bởi điều kiện clear, offline hoặc archive); evaluator poll thay vì LISTEN/NOTIFY; chưa có thao tác xóa rule.
- ML anomaly detection, predictive maintenance và work-order workflow không thuộc IT-5; EAMO sở hữu maintenance workflow.
- Command/RPC có topic/ACL nền nhưng command API, trạng thái command và ACK processing chưa thuộc implementation telemetry hiện tại.
- [Phase 6](../plan/phase-06-eamo-integration.md): giao alert event cho EAMO qua hàng đợi (Redis Streams) dựa trên alert/outbox hiện có; envelope có version, `sequence` theo alert, consumer contract, DLQ và mapping Equipment vẫn là kế hoạch.
- Chưa có retention/partitioning/downsampling policy cho samples, dedup identity và outcomes.
- In-process event hubs cần thay hoặc bổ sung shared fan-out nếu chạy nhiều service replica.

## 14. Điểm vào code chính

| Phần | File |
| --- | --- |
| Startup, health/readiness, WebSocket routes | telemetry-service/app/main.py |
| MQTT connect/subscribe/callback/ACK/reconnect | telemetry-service/app/ingress/mqtt_subscriber.py |
| Parse và chuẩn hóa envelope | telemetry-service/app/telemetry/envelope.py |
| Profile validation | telemetry-service/app/telemetry/validation.py |
| Resolve, transaction, persist, outcome | telemetry-service/app/storage/telemetry_ingest.py, telemetry-service/app/storage/queries.py |
| Presence update/monitor/hubs | telemetry-service/app/storage/presence.py, presence_monitor.py, telemetry-service/app/realtime/presence_hub.py |
| REST, tickets, authorization | backend/modules/Admin/Routes/telemetry.php, backend/modules/Admin/Services/Telemetry/, backend/modules/Admin/Requests/Telemetry/ |
| Frontend API/events/UI | web/app/composables/useTelemetry.ts, useTelemetryStream.ts, useDevicePresenceStream.ts, web/app/pages/devices/ |
| Rule engine thuần, parse definition | telemetry-service/app/rules/engine.py, telemetry-service/app/rules/definition.py |
| Evaluator, alert/outbox ghi từ Python | telemetry-service/app/storage/rule_evaluation.py, telemetry-service/app/storage/alerting.py |
| Rule/alert/webhook API | backend/modules/Admin/Routes/alert-rule.php, alert.php, webhook.php và Actions, Requests, Policies, Services tương ứng |
| Gửi webhook, chống SSRF | backend/modules/Admin/Services/Webhook/ (WebhookDispatcher, WebhookDestinationGuard), backend/config/webhooks.php |
| Lệnh vận hành | backend/modules/Admin/Console/Webhook/DispatchWebhooksCommand.php, backend/modules/Admin/Console/Alert/AlertingStatsCommand.php |
| Migration alerting | backend/database/migrations/2026_10_18_000001_create_alerting_tables.php |
| Màn Alerts | web/app/pages/alerts.vue, web/app/components/alerting/, web/app/composables/useAlerting.ts, web/app/types/alerting.ts |
| Nullable profile version migration | backend/database/migrations/2026_10_15_174624_allow_profileless_telemetry_samples.php |
| Test coverage | telemetry-service/tests/, web/tests/telemetryContract.test.ts, web/tests/alertingContract.test.ts, backend/tests/Feature/ (AlertRuleApiTest, AlertApiTest, Webhook*Test, AlertingStatsCommandTest) |
