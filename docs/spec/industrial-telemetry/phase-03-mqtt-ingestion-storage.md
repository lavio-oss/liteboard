# IT-3 — MQTT ingestion và time-series persistence

> Trạng thái: kế hoạch; Data Plane hiện mới xử lý JSON/topic cơ bản và presence, chưa lưu telemetry bền vững.

## Mục tiêu

Đưa một message từ broker vào PostgreSQL đúng Project, validate theo Profile/schema, lưu bền vững và chịu được QoS 1 redelivery.

## Luồng

```text
MQTT TLS + broker Access Token/ACL
  → parse canonical topic
  → resolve Device + Project + assigned Profile
  → size/envelope/time/schema validation
  → normalize
  → PostgreSQL insert + unique idempotency boundary
  → structured processing outcome
```

Topic giữ contract hiện hành `liteboard/{project_id}/devices/{device_id}/telemetry`.

```json
{
  "message_id": "01J...",
  "schema_version": 1,
  "occurred_at": "2026-10-07T10:00:00Z",
  "values": { "temperature": 82.1, "vibration": 8.4, "rpm": 1450 }
}
```

## Dữ liệu và xử lý

- Bản ghi phải liên hệ được tới Device và Project, kèm `message_id`, `schema_version`, `occurred_at`, server `received_at`, values đã validate và outcome.
- Producer phải giữ `message_id` ổn định khi retry cùng một measurement; ID chỉ cần duy nhất trong phạm vi Device.
- Database unique constraint trên `(device_id, message_id)` là ranh giới đúng đắn cho deduplication. Redis có thể tối ưu lookup nhưng không được đứng trước database như nguồn bảo đảm duy nhất.
- Chốt retention, batch/flush policy và handling khi PostgreSQL lỗi; xác minh MQTT QoS 1 acknowledgement/retry với client library trước khi tuyên bố không mất message.
- Không log Access Token, provisioning secret hoặc toàn bộ payload nhạy cảm.

## Nghiệm thu

- Broker từ chối credential/topic không hợp lệ theo Access Token và ACL. Với message đã qua broker, Data Plane xác nhận cặp Project/Device và trả reason tương ứng cho topic/JSON/envelope/schema sai.
- Gửi lại cùng `(device_id, message_id)` không tạo logical duplicate; cùng message ID trên Device khác không bị nhầm là trùng.
- Khi PostgreSQL lỗi/restart, behavior được quan sát, retry/recovery được đo; không xác nhận acceptance chỉ từ log publisher.
- L0: 10 Device × 1 message/giây × 5 phút; reconcile sent/accepted/rejected/duplicate/persisted, đo p50/p95/p99 và bounded memory.
- Dữ liệu lưu/query phải luôn được scope qua Tenant → Project → Device, không tin ID topic/payload như authority.

## Ngoài phạm vi

Rule evaluation, REST history API, WebSocket broadcast và TimescaleDB extension thuộc các phase sau.
