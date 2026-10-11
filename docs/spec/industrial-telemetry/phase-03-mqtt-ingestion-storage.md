# IT-3 — MQTT ingestion và time-series persistence

> Trạng thái: đang lập kế hoạch. Data Plane hiện parse topic/JSON, cập nhật presence và validate telemetry theo Profile Version; chưa persist telemetry hoặc processing outcome và chưa deduplicate `message_id`.

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

`message_id`, `schema_version`, `occurred_at` và `values` đều bắt buộc. `schema_version` phải khớp Profile Version server đã gán cho Device; client không được chọn schema của Project hoặc Device khác. Thời điểm phải parse được, có timezone và qua policy clock-skew đã chốt. Giới hạn payload size và chính sách retained message phải được cấu hình rõ.

## Persistence và idempotency

- Sample giữ `project_id`, `device_id`, Profile Version ID, `message_id`, `occurred_at`, server `received_at`, values đã validate và processing status. Project là owner trực tiếp; Tenant được resolve qua Project → Tenant, không lấy từ payload.
- Nếu benchmark sau này yêu cầu materialize `tenant_id` để query/partition, nó chỉ là scope index và phải được ràng buộc với Project bằng composite foreign key/constraint để không tạo nguồn ownership thứ hai.
- Bản ghi dedup có unique boundary `(device_id, message_id)`. Producer phải giữ nguyên ID khi retry cùng measurement. Cùng ID/cùng nội dung là duplicate; cùng ID/khác nội dung trả reason `idempotency_conflict` và không âm thầm chấp nhận nội dung nào.
- Ranh giới dedup phải tồn tại ít nhất bằng thời hạn cho phép redelivery, kể cả khi sample hết retention. Định nghĩa riêng retention cho samples, dedup identities và processing outcomes.
- Chốt contract giá trị xử lý: `validated` chỉ qua kiểm tra; `persisted` đã commit; `duplicate` trùng hợp lệ; `rejected` sai dữ liệu; lỗi hạ tầng là retryable/failed. Không log `accepted` trước khi commit.
- DB transaction cần bao trùm sample, dedup identity và processing outcome. Event để resume rule evaluation cần được ghi cùng transaction hoặc có cơ chế replay tương đương.
- Quy định bounded buffering, batch/flush, admission khi quá tải, retry/backoff, graceful shutdown và dead-letter/failed visibility. Không dùng Redis làm nguồn bảo đảm duy nhất cho sample hay event quan trọng.
- Xác minh semantics MQTT ACK, persistent session, reconnect và redelivery theo đúng phiên bản client/broker. MQTT PUBACK phía producer không tự chứng minh PostgreSQL đã commit.
- Không lưu hoặc log Access Token, provisioning secret hay toàn bộ payload nhạy cảm trong outcome/trace.

## Nghiệm thu

- Credential/topic sai bị broker ACL từ chối. Với message tới Data Plane, topic không đúng contract, Device không tồn tại/không active/chưa approved, Project hoặc Tenant không active, JSON/envelope/schema sai có outcome ổn định và không làm lộ tài nguyên cross-tenant.
- Project ID trong topic phải khớp Project của Device; tất cả persistence, retry work và truy vấn gắn lại đúng Tenant → Project → Device.
- Retry cùng `(device_id, message_id)` không tạo sample logic thứ hai. Cùng ID trên Device khác được phép; cùng ID với payload khác bị đánh dấu conflict.
- Inject DB outage và restart worker ở trước/sau commit; chứng minh không mất sample được báo `persisted`, duplicate có thể reconcile, buffer bị giới hạn và recovery được đo.
- L0: 10 Device × 1 message/giây × 5 phút; reconcile sent, persisted, rejected, duplicate, failed và conflict; ghi p50/p95/p99 cùng queue depth/memory và recovery. L0 là development gate, không phải capacity claim.

## Ngoài phạm vi

REST history/latest, WebSocket broadcast và rule evaluation đầy đủ thuộc các phase sau. IT-3 vẫn ghi processing outcomes và durable evaluation work cần để phase sau có thể resume sau crash.
