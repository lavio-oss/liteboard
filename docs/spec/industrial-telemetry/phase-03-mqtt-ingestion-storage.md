# IT-3 — MQTT ingestion và time-series persistence

> Trạng thái: đã triển khai migration, envelope validation, transaction persistence, deduplication và commit-aware MQTT ACK. Unit tests cùng PostgreSQL/Mosquitto integration tests đã qua; L0 development run trên PostgreSQL/Mosquitto cô lập cũng đã qua. Pilot capacity và fault-injection trên môi trường triển khai vẫn cần xác nhận.

## Mục tiêu

Đưa message từ broker vào PostgreSQL theo đúng Tenant/Project/Device, validate theo schema version được gán, ghi bền vững và xử lý an toàn khi QoS 1 redelivery, PostgreSQL lỗi hoặc worker khởi động lại.

## Luồng

```text
MQTT TLS + broker Access Token/ACL
  → parse canonical topic
  → resolve active Device + Project + Tenant + assigned Profile Version
  → enforce size/envelope/time/schema validation
  → normalize at the ingestion boundary
  → transaction: telemetry + dedup identity + processing outcome
  → acknowledge only after the durable boundary is verified
```

Topic giữ contract hiện hành `liteboard/{project_id}/devices/{device_id}/telemetry`. Topic và payload IDs chỉ là routing context; Data Plane phải resolve chúng về Device/Project phía server. Query resolve context phải thống nhất với điều kiện broker hiện hành về Device, Project, Tenant status và Device approval.

## Envelope

```json
{
  "message_id": "01J...",
  "schema_version": 1,
  "occurred_at": "2026-10-07T10:00:00Z",
  "values": { "temperature": 82.1, "vibration": 8.4, "rpm": 1450 }
}
```

`message_id`, `schema_version`, `occurred_at` và `values` đều bắt buộc. `message_id` là chuỗi không rỗng tối đa 128 ký tự; `schema_version` là số nguyên dương và phải khớp Profile Version server đã gán cho Device. `values` phải là object. Payload tối đa 64 KiB. `occurred_at` phải là ISO 8601 có timezone; chỉ từ chối thời điểm quá 5 phút trong tương lai, không từ chối dữ liệu đến muộn. Client không được chọn schema của Project hoặc Device khác.

Subscription MQTT v5 dùng `retain_as_published`; retained telemetry bị ghi outcome `rejected` với reason `retained_message`. Cờ retained không đổi cách xử lý heartbeat. Presence được cập nhật cho Device đang active, approved trong Project và Tenant active trước khi kiểm tra envelope/schema; schema rejection vẫn giữ presence.

## Persistence và idempotency

- Sample PostgreSQL giữ `project_id`, `device_id`, Profile Version ID đã gán, `message_id`, `occurred_at`, server `received_at`, values JSONB đã validate và status `persisted`. Project là owner trực tiếp; Tenant được resolve qua Project → Tenant, không lấy từ payload. Composite foreign key chặn sample trỏ Device/Profile Version thuộc Project khác; xóa Device theo lifecycle hiện tại sẽ cascade dữ liệu Device-owned.
- Nếu benchmark sau này yêu cầu materialize `tenant_id` để query/partition, nó chỉ là scope index và phải được ràng buộc với Project bằng composite foreign key/constraint để không tạo nguồn ownership thứ hai.
- Bản ghi dedup có unique boundary `(device_id, message_id)`. Producer phải giữ nguyên ID khi retry cùng measurement. Cùng ID/cùng nội dung là duplicate; cùng ID/khác nội dung trả reason `idempotency_conflict` và không âm thầm chấp nhận nội dung nào.
- Ranh giới dedup phải tồn tại ít nhất bằng thời hạn cho phép redelivery, kể cả khi sample hết retention. Định nghĩa riêng retention cho samples, dedup identities và processing outcomes.
- Outcome lưu `persisted`, `rejected`, `duplicate` hoặc `idempotency_conflict`; lỗi hạ tầng không ghi outcome và không ACK để broker retry. Rejected outcome chỉ giữ `message_id` hợp lệ và reason metadata, không lưu raw payload. Message không resolve được về Device/Project hợp lệ không tạo tenant-owned row.
- Một transaction bao trùm presence, sample, dedup identity và outcome. Sample đã commit là nguồn bền vững để phase sau replay rule evaluation; phase này chưa tạo rule event/outbox.
- Consumer giữ client ID `liteboard-telemetry-service`, MQTT v5 persistent session, `optimistic_acknowledgement=False`, receive maximum mặc định 20. Mosquitto giới hạn 20 in-flight, 1.000 queued messages và 64 MiB queued bytes. Chạm giới hạn là degraded condition; không tính L0 đạt.
- `GET /ready` kiểm tra PostgreSQL và MQTT subscriber; `GET /health` chỉ kiểm tra tiến trình. Không thêm TimescaleDB, Redis queue, REST query, WebSocket, batching hay tự động retention trong IT-3.
- `gmqtt==0.7.0` được pin; callback chỉ trả PUBACK success sau commit. Khi PostgreSQL lỗi, callback không ACK và reconnect bằng client ID/persistent session cũ. MQTT PUBACK từ producer vẫn không tự chứng minh PostgreSQL đã commit.
- Không lưu hoặc log Access Token, provisioning secret hay toàn bộ payload nhạy cảm trong outcome/trace.

## Nghiệm thu

- Credential/topic sai bị broker ACL từ chối. Với message tới Data Plane, topic không đúng contract, Device không tồn tại/không active/chưa approved, Project hoặc Tenant không active, JSON/envelope/schema sai có outcome ổn định và không làm lộ tài nguyên cross-tenant.
- Project ID trong topic phải khớp Project của Device; tất cả persistence, retry work và truy vấn gắn lại đúng Tenant → Project → Device.
- Retry cùng `(device_id, message_id)` không tạo sample logic thứ hai. Cùng ID trên Device khác được phép; cùng ID với payload khác bị đánh dấu conflict.
- Có unit coverage cho envelope, schema version, kiểu dữ liệu, giới hạn payload, clock skew, retained flag và không ghi raw payload; PostgreSQL integration coverage cho trạng thái scope, uniqueness và idempotency.
- Mosquitto integration kiểm tra QoS 1 redelivery sau lỗi storage và retained telemetry so với heartbeat. Crash sau commit nhưng trước ACK được bảo vệ bằng dedup identity; live fault-injection trên broker cần xác nhận lại trước khi đóng runtime gate.
- L0 dev: 10 Device × 1 message/giây × 5 phút đã gửi 3.000/3.000 message, persisted 3.000, rejected/duplicate/conflict/failed 0, unexplained 0. Transaction p50/p95/p99 là 11.189/24.836/43.171 ms; DB latency trung bình 14.005 ms; callback in-flight tối đa 3; outstanding chưa commit tối đa 3 trên backlog limit 1.000; Python tracemalloc peak 0.705 MiB. Recovery probe với một simulated storage failure redeliver và commit thành công sau 6.053 giây. Đây là kết quả trên PostgreSQL 16 và Mosquitto cô lập local; outstanding là queue proxy phía consumer, chưa phải broker queue-depth metric và không phải capacity claim cho pilot.

## Ngoài phạm vi

REST history/latest (đưa sang IT-4), WebSocket broadcast và rule evaluation đầy đủ thuộc các phase sau. IT-3 chỉ cần lưu sample/outcome bền vững để phase sau có thể replay sau crash.
