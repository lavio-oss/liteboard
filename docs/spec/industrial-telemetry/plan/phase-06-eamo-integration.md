# Phase 6: Giao Alert Event cho EAMO qua Hàng đợi (Queue-based Delivery)

> Trạng thái: Kế hoạch. Transactional outbox và kênh webhook HTTP đã có từ IT-5 (xem [mục 12 của flow hiện hành](../current/telemetry-flow.md#12-rule-alert-và-webhook-it-5)). Envelope có version, số thứ tự theo alert, hàng đợi, consumer contract và mapping Equipment chưa triển khai.
> Thuộc kế hoạch: [Roadmap telemetry công nghiệp](../README.md)
> Tầng kiến trúc: **Laravel Control Plane (outbox relay) + Python Data Plane (`telemetry-service`, nơi sinh alert) + Redis Streams + Consumer của EAMO**
> Phụ thuộc: alert lifecycle và durable outbox ([mục 12.4–12.5 flow hiện hành](../current/telemetry-flow.md#12-rule-alert-và-webhook-it-5))

---

## 1. Mục tiêu của Phase 6

Đưa mọi chuyển trạng thái của alert (phát hiện, hồi phục, xác nhận, đổi mức độ) sang EAMO và các consumer khác **qua một hàng đợi bền vững**, thay vì để EAMO phải nhận từng HTTP request:

1. **Không mất event**: Postgres outbox vẫn là nguồn sự thật, ghi cùng transaction với chuyển trạng thái alert. Hàng đợi chỉ là phương tiện vận chuyển và có thể dựng lại từ outbox.
2. **Consumer tự điều tốc**: EAMO đọc theo tốc độ của mình, ack sau khi đã commit nghiệp vụ; khi EAMO ngừng, event dồn lại trong hàng đợi và được xử lý tiếp khi phục hồi.
3. **Đúng thứ tự theo alert**: mỗi event mang số thứ tự (`sequence`) trong alert; consumer không áp dụng event cũ sau event mới.
4. **Quan sát và đối soát được**: biết backlog, tuổi event, event độc (poison) và lệch trạng thái giữa LiteBoard với EAMO.

Hợp đồng cam kết **at-least-once delivery** kết hợp consumer idempotent để đạt một tác động nghiệp vụ logic. LiteBoard không tuyên bố exactly-once.

---

## 2. Hiện trạng và khoảng cách

| Hạng mục | Đã có (IT-5) | Cần làm ở Phase 6 |
| --- | --- | --- |
| Ghi event cùng transaction với chuyển trạng thái | `outbox_events` (`idempotency_key` unique, `payload` JSONB) | Giữ nguyên, thêm `sequence` theo alert |
| Vận chuyển | `webhooks:dispatch` gửi HTTP, ký HMAC, retry 8 lần, Redeliver thủ công | Thêm kênh hàng đợi; webhook vẫn là kênh cho consumer nhỏ |
| Envelope | Payload v1 (`alert.opened`…), thiếu `event_version`, `emitted_at`, `sequence`, provenance, evidence | Envelope v1 đầy đủ (mục 3.2) |
| Mapping Equipment sang EAMO | Chưa có | Bảng mapping, chỉ gửi `external_id` khi có cấu hình (mục 3.7) |
| Consumer contract, receiver mẫu | Chưa có | Inbox/dedup, ack, hành vi khi sai version (mục 3.5) |
| Dead-letter, replay, đối soát | Chỉ Redeliver từng lần giao | Hàng DLQ, lệnh republish, báo cáo đối soát (mục 3.6, 3.8) |
| Số đo | `alerting:stats` (tuổi outbox, delivery theo trạng thái) | Độ trễ publish/end-to-end, lag, tuổi pending, độ sâu DLQ (mục 3.8) |

---

## 3. Thiết kế Kỹ thuật Chi tiết

### 3.1. Luồng tổng thể

```text
Alert transition (evaluator Python / Laravel acknowledge, archive)
        │  một transaction
        ▼
outbox_events  ── (source of truth, có sequence theo alert)
        │
        │  outbox:relay  (worker Laravel, FOR UPDATE SKIP LOCKED)
        ▼
Redis Streams  lb:ev:{integration_id}:{partition}
        │  XREADGROUP (consumer group)
        ▼
Consumer EAMO ── 1 transaction: ghi inbox(event_id) + thay đổi nghiệp vụ ──▶ XACK
        │ quá số lần giao / version không hỗ trợ
        ▼
lb:ev:{integration_id}:dlq
```

Mỗi **integration** thuộc đúng một Project, có danh sách loại event cần nhận, `transport` (`queue` hoặc `webhook`) và mapping riêng.

---

### 3.2. Event envelope v1

```json
{
  "event_id": "<outbox-event-uuid>",
  "event_type": "equipment.condition.detected",
  "event_version": 1,
  "sequence": 1,
  "occurred_at": "2026-10-07T10:00:30.123456Z",
  "emitted_at": "2026-10-07T10:00:30.130000Z",
  "tenant_id": "<tenant-uuid>",
  "project_id": "<project-uuid>",
  "alert_id": "<alert-uuid>",
  "rule": { "id": "<rule-uuid>", "code": "high_temperature", "revision": 2 },
  "equipment": {
    "source_id": "<liteboard-asset-uuid>",
    "source_code": "MOTOR-01",
    "external_id": "<eamo-equipment-id>"
  },
  "device": { "id": "<device-uuid>", "code": "motor-01" },
  "condition": "high_temperature",
  "severity": "CRITICAL",
  "source_message": {
    "id": "<message_id>",
    "sample_id": "<sample-uuid>",
    "profile_version_id": "<profile-version-uuid>"
  },
  "evidence": { "field": "temperature", "value": 82.1, "unit": "celsius" }
}
```

Quy tắc:

- `event_type` ánh xạ từ loại alert hiện tại: `alert.opened` thành `equipment.condition.detected`, `alert.cleared` thành `equipment.condition.recovered` (kèm `clear_reason`: `condition_cleared`, `device_offline` hoặc `rule_archived`), `alert.acknowledged` thành `equipment.condition.acknowledged`, `alert.severity_changed` thành `equipment.condition.severity_changed`. Mỗi loại có payload riêng được version hóa.
- `event_version` version hóa envelope và event type, **không phải** `schema_version` của telemetry.
- `event_id` là id của dòng outbox: **ổn định qua mọi lần publish lại**.
- `sequence` tăng dần theo từng alert (1 là lúc mở, 2 là lần chuyển trạng thái kế tiếp…), cấp phát trong cùng transaction với chuyển trạng thái. Cặp (`alert_id`, `sequence`) là duy nhất.
- `occurred_at` lấy từ thời điểm điều kiện chuyển trạng thái theo policy alert (giờ server `received_at` của mẫu); `emitted_at` là lúc LiteBoard ghi dòng outbox.
- `equipment.external_id` chỉ có khi tồn tại mapping cấu hình rõ (mục 3.7). `source_code` như `MOTOR-01` không mặc nhiên duy nhất ngoài Project.
- `evidence` lấy từ evidence đã giới hạn kích thước của alert; payload không chứa raw MQTT payload hay secret.
- Payload chỉ là ngữ cảnh. Không bao giờ cấp quyền truy cập vào dữ liệu LiteBoard.

---

### 3.3. Outbox sang hàng đợi (Relay)

Worker `outbox:relay` (cùng kiểu với `webhooks:dispatch`) chạy dài hạn:

1. Chọn các dòng outbox chưa publish cho integration có `transport = queue`, `ORDER BY created_at, id`, `FOR UPDATE SKIP LOCKED`, kiểm tra `outbox.project_id = integration.project_id`.
2. `XADD` vào stream của partition `crc32(alert_id) % N` (mặc định `N = 1`), field `event` chứa envelope.
3. Đánh dấu `published_at` sau khi `XADD` thành công, ngoài transaction chọn dòng.

Relay sập giữa bước 2 và 3 sẽ publish lại dòng đó. Đây là trùng lặp có chủ đích, được `event_id` dedup ở consumer. Stream bị mất hoặc Redis được dựng lại: reset `published_at` theo khoảng thời gian rồi chạy lại relay (mục 3.8).

---

### 3.4. Cấu trúc hàng đợi (Redis Streams)

- **Stream theo integration, không dùng chung giữa các Tenant**: `lb:ev:{integration_id}:{partition}`; DLQ là `lb:ev:{integration_id}:dlq`.
- **Partition theo `alert_id`** để mọi event của một alert đi cùng một stream; mỗi partition tại một thời điểm do một consumer xử lý (thứ tự theo alert). Không có bảo đảm thứ tự giữa các alert.
- **Consumer group** (ví dụ `eamo`) dùng `XREADGROUP`, `XACK`, và `XAUTOCLAIM` để lấy lại event chưa ack quá thời gian ẩn (visibility timeout).
- **Retention**: `XTRIM MINID` theo tuổi (đề xuất 7 ngày) sau khi đã ack; outbox giữ lâu hơn stream để replay.
- **Độ bền**: Redis cần bật AOF (`appendonly yes`, `appendfsync everysec`); cấu hình Redis hiện tại trong docker-compose **chưa được xác nhận** cho việc này. Do outbox là nguồn sự thật nên mất một phần stream không làm mất event.

---

### 3.5. Consumer contract (EAMO)

1. Đọc event, kiểm tra `event_version`. Version không hỗ trợ: không xử lý, ghi lý do và chuyển sang DLQ (không retry vô hạn).
2. Trong **một transaction**: kiểm tra và ghi `event_id` vào bảng inbox/dedup, đồng thời áp dụng thay đổi nghiệp vụ. Nếu `event_id` đã có thì bỏ qua nghiệp vụ nhưng vẫn ack (duplicate).
3. **Chỉ ack sau khi commit.** Sập trước ack thì event được giao lại và bị inbox chặn; sập trước commit thì event được giao lại và áp dụng đúng một lần.
4. **Thứ tự theo alert:** lưu `last_applied_sequence(alert_id)`. `sequence` không lớn hơn giá trị đã lưu là cũ hoặc trùng nên bỏ qua. Lệch quãng (`sequence` lớn hơn mong đợi) thì giữ lại tối đa một thời gian cấu hình rồi yêu cầu đối soát, không áp dụng bừa.
5. Ack khác với "nghiệp vụ hoàn tất ở EAMO". Work order, phân công và lịch sử bảo trì là việc của EAMO.

---

### 3.6. Retry, event độc và Dead-letter

| Tình huống | Hành vi |
| --- | --- |
| Consumer chưa ack trong visibility timeout (đề xuất 60 giây) | `XAUTOCLAIM` giao lại, tăng số lần giao |
| Lỗi tạm thời ở consumer | Không ack, giao lại với backoff do consumer thực hiện |
| Quá số lần giao tối đa (đề xuất 8) | Chuyển sang `…:dlq` kèm lý do, số lần giao, lỗi cuối; ack bản gốc |
| `event_version` không hỗ trợ, payload sai hợp đồng | Chuyển ngay sang DLQ, không retry |
| DLQ không rỗng | Cảnh báo vận hành; xử lý xong dùng lệnh requeue (mục 3.8) |

---

### 3.7. Bảo mật, Tenant và Mapping

- **Cô lập Tenant/Project:** mỗi integration thuộc một Project; relay từ chối publish dòng outbox sang integration khác Project. Mapping Equipment chỉ chấp nhận asset cùng Project với integration.
- **Mapping Equipment sang EAMO:** bảng `equipment_external_refs` (`integration_id`, `asset_id`, `external_id`), unique theo (`integration_id`, `asset_id`) và (`integration_id`, `external_id`); do project Admin quản lý. Không có dòng mapping thì không gửi `external_id`.
- **Truy cập hàng đợi:** mỗi integration có Redis ACL user riêng, chỉ được `XREADGROUP`, `XACK`, `XAUTOCLAIM` trên stream của chính nó, kết nối qua TLS. Credential này tách biệt với Access Token MQTT của thiết bị và với secret HMAC của webhook; có quy trình xoay vòng.
- Cấu hình integration (endpoint/stream, mapping, credential) chỉ project Admin thay đổi được; mọi thay đổi có audit.

---

### 3.8. Quan sát và vận hành

Số đo (mở rộng `alerting:stats`):

- **Relay:** số dòng outbox chưa publish, tuổi dòng cũ nhất, độ trễ từ `created_at` đến `published_at`.
- **Hàng đợi:** độ dài stream, số entry pending, tuổi entry pending cũ nhất, lag của consumer group, số lần giao lại, độ sâu DLQ.
- **Đầu cuối:** độ trễ từ `emitted_at` đến lúc ack, thời gian phục hồi sau khi consumer ngừng.
- **Đối soát:** chênh lệch giữa `sequence` cao nhất mỗi alert trong outbox và `last_applied_sequence` do EAMO báo.

Runbook (cần viết khi triển khai):

- **Replay:** `outbox:republish --integration=… --from=… --to=…` đặt lại `published_at` theo khoảng rồi relay chạy lại; consumer dedup bằng `event_id`.
- **Requeue DLQ:** kiểm tra lý do, sửa nguyên nhân, đưa event về stream chính.
- **Đối soát:** so sánh theo `alert_id`, republish các event còn thiếu.
- **Redis mất dữ liệu:** dựng lại stream từ outbox như mục 3.3.

---

## 4. Quyết định cần chốt trước khi triển khai

| Quyết định | Đề xuất | Cần xác nhận từ |
| --- | --- | --- |
| Công nghệ hàng đợi | Redis Streams (đã có Redis trong stack, roadmap Phase 8/9 cũng dùng Redis) | LiteBoard |
| Vị trí mạng của EAMO | Cùng mạng thì dùng thẳng; ở ngoài thì cần cổng (HTTP long-poll hoặc broker công khai có TLS + ACL) thay vì mở Redis | EAMO |
| Định dạng `event_id` | UUID của dòng outbox; đổi sang ULID/UUIDv7 nếu EAMO cần sắp xếp theo thời gian | EAMO |
| Số partition, retention, visibility timeout, số lần giao tối đa | 1 partition, 7 ngày, 60 giây, 8 lần | LiteBoard + EAMO |
| Chính sách lệch quãng `sequence` | Giữ tối đa N giây rồi yêu cầu đối soát | EAMO |
| Cách ghi `severity` | Viết hoa (`CRITICAL`…) theo envelope | EAMO |
| Chủ sở hữu mapping Equipment | Project Admin phía LiteBoard | LiteBoard + EAMO |
| Kênh webhook HTTP | Giữ cho consumer nhỏ, dùng chung envelope v1 | LiteBoard |

---

## 5. Checklist Nghiệm thu Phase 6

- [ ] Alert phát hiện và hồi phục đến consumer qua hàng đợi; payload tra ngược được tới rule revision, alert, Device, Profile Version và mẫu nguồn.
- [ ] Kill relay giữa chừng: không mất event, event trùng bị consumer chặn bằng `event_id`.
- [ ] Redis khởi động lại hoặc mất stream: republish từ outbox khôi phục đủ event; trạng thái của consumer nhất quán.
- [ ] Consumer sập sau khi xử lý nhưng trước ack: event được giao lại, không lặp tác động nghiệp vụ.
- [ ] Consumer sập trước commit: event được giao lại và áp dụng đúng một lần.
- [ ] `event_version` không hỗ trợ đi thẳng vào DLQ với lý do rõ ràng, không retry vô hạn.
- [ ] Event đến sai thứ tự (`sequence` 3 trước 2): consumer không để event cũ ghi đè trạng thái mới.
- [ ] Consumer ngừng rồi phục hồi: backlog được xử lý hết, đúng thứ tự theo alert; số đo thể hiện lag và thời gian phục hồi.
- [ ] Consumer của Tenant khác không đọc được stream của integration này (kiểm tra ACL); mapping sai Project/Tenant bị từ chối.
- [ ] Số đo mục 3.8 có đủ trong `alerting:stats`; báo cáo đối soát chỉ ra chênh lệch khi cố tình bỏ một event.
- [ ] Có receiver mẫu và contract test không dùng credential production; runbook replay/đối soát đã viết.

---

## 6. Ngoài phạm vi

Không chuyển work order, phân công technician hoặc lịch sử bảo trì sang LiteBoard; EAMO sở hữu các nghiệp vụ này. Không ràng buộc LiteBoard chỉ phục vụ EAMO: các consumer khác vẫn dùng được hàng đợi hoặc webhook. Không xây dựng exactly-once network delivery.
