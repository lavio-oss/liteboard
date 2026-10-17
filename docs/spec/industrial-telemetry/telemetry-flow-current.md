# Telemetry service và các luồng liên quan — trạng thái hiện tại

> Đối chiếu code và contract trong repository ngày 2026-10-16. Tài liệu này mô tả implementation hiện tại, không xác nhận môi trường pilot hay production. Tài liệu IT-1 đến IT-4 cũ được thay bằng file này; roadmap IT-5 và IT-6 vẫn để riêng.

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

## 2. Ranh giới dữ liệu và quyền

- Tenant là ranh giới cô lập. Project, Device, Equipment/Asset, Device Profile và telemetry của thiết bị thuộc Tenant qua quan hệ Project.
- project_id và device_id trong MQTT topic chỉ giúp định tuyến message tới bản ghi cần resolve; chúng không cấp quyền. Telemetry service xác nhận cặp Project/Device và trạng thái Device, Project, Tenant trong PostgreSQL.
- Telemetry sample lưu project_id và device_id. Tenant được suy ra từ Device → Project → Tenant; không lấy tenant_id từ firmware hoặc payload.
- Profile và version thuộc Project. Device có thể chưa gán profile. Khi đã gán, version được dùng để validate là version phía server đang gắn với Device; client không được tự chọn profile của Project khác.
- Device là định danh MQTT, tách biệt với Equipment/Asset; Device có thể liên kết tùy chọn tới Equipment Asset trong cùng Project. Không có bảng Motor riêng trong flow này.
- Control Plane có CRUD Device Profile và tạo version theo Project. Version đã tạo là bất biến/append-only; chỉnh schema tạo version mới. Device Profile Version chưa bị backfill vào các sample đã lưu.
- REST cho telemetry dùng Sanctum, tenant.context, read ability, scoped route binding và Device policy. Presence ticket kiểm tra viewTenantDevices. API unresolved trung tâm chỉ dành cho platform admin vì topic chưa resolve có thể chứa ID không đáng tin cậy.
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
9. Khi transaction commit xong, callback trả MQTT subscriber PUBACK success. Sau đó service phát WebSocket event best-effort. Nếu xử lý/ghi PostgreSQL thất bại trong flow này, callback lỗi, service lên lịch reconnect và không chủ động ACK message đó.

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

Telemetry có topic hợp lệ từ Device đang hoạt động cập nhật last_seen_at trước validation; heartbeat JSON hợp lệ cũng cập nhật. Presence monitor refresh khi service khởi động rồi mỗi 5 giây, dùng last_seen_at để chuyển online → unstable → offline; browser WebSocket disconnect không tác động trạng thái thiết bị. Một sample tới muộn hoặc bị reject không làm latest telemetry lùi, dù vẫn có thể chứng minh Device vừa gửi tín hiệu.

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
- /device-profiles là màn quản lý profile/schema theo Project; tạo version mới và gán version vào Device đi qua Control Plane API.
- /devices/{projectId}/{deviceId} có Overview, Telemetry, Message Inspector. Overview lấy Device và latest values qua REST. Telemetry lấy history/aggregates qua REST rồi ghép sample realtime. Late sample không ghi đè latest theo quy tắc event-time.
- Message Inspector tải recent outcomes, lọc theo message ID hoặc processing ID, hiển thị status và một reason code tóm tắt; click View trace gọi REST rồi mở drawer với reason chi tiết, processing stages, thời gian, và sample nếu có.
- Drawer hiển thị **“No validation errors.”** khi reason_codes rỗng (bao gồm message profile-less mới được persist); outcome lịch sử skipped + schema_not_assigned cũng được coi là không có lỗi validation. Stage schema_validation_skipped chỉ nói profile schema không chạy.
- Browser parse event bằng Zod; sample merge theo id, outcome merge theo processing_id. Khi stream reconnect, frontend tải REST history/outcomes để bù gap. Presence stream tải lại device snapshot. Tenant đổi thì state thuộc tenant cũ được xóa.
- Stream status chỉ được giữ trong composable cho reconnect/permission handling. Component badge “Presence: Live” hoặc “Telemetry: Live” không nằm trong trang hiện tại; trạng thái hiển thị cho người dùng là trạng thái kết nối Device và outcome từng message.

API calls đi qua useApi/useTelemetry, không gọi REST trực tiếp từ table/drawer component.

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
- Chưa có số liệu pilot/production, browser acceptance mới nhất, hoặc chứng minh multi-instance WebSocket fan-out. Các phép đo local ở trên không thay thế những cổng này.
- telemetry-service/tests/ có unit, PostgreSQL integration và MQTT integration coverage; test_postgres_integration.py/test_mqtt_integration.py cần dịch vụ tích hợp tương ứng. Các kết quả lịch sử cần được chạy lại trên commit/môi trường cần nghiệm thu.

## 12. Việc chưa thuộc flow hiện tại

- IT-5 rule evaluation, alert lifecycle, transactional outbox, webhook/retry chưa được triển khai.
- Command/RPC có topic/ACL nền nhưng command API, trạng thái command và ACK processing chưa thuộc implementation telemetry hiện tại.
- IT-6 event contract/integration EAMO phụ thuộc alert/outbox và vẫn là roadmap.
- Chưa có retention/partitioning/downsampling policy cho samples, dedup identity và outcomes.
- In-process event hubs cần thay hoặc bổ sung shared fan-out nếu chạy nhiều service replica.

## 13. Điểm vào code chính

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
| Nullable profile version migration | backend/database/migrations/2026_10_15_174624_allow_profileless_telemetry_samples.php |
| Test coverage | telemetry-service/tests/, web/tests/telemetryContract.test.ts |
