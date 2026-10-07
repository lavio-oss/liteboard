# IT-6 — Event contract với EAMO

> Trạng thái: kế hoạch; phụ thuộc IT-5 alert/outbox và event idempotency.

## Mục tiêu

Giao một alert industrial từ LiteBoard sang EAMO bằng event versioned, có thể retry và đối soát mà không trùng nghiệp vụ.

## Event tối thiểu đề xuất

```json
{
  "event_id": "01J...",
  "event_type": "equipment.condition.detected",
  "schema_version": 1,
  "tenant_id": "<tenant-uuid>",
  "project_id": "<project-uuid>",
  "equipment": { "id": "<asset-uuid>", "code": "MOTOR-01" },
  "device": { "id": "<device-uuid>", "code": "motor-01" },
  "condition": "high_temperature",
  "severity": "CRITICAL",
  "occurred_at": "2026-10-07T10:00:30Z",
  "source_message_id": "01J...",
  "idempotency_key": "<stable-alert-event-key>",
  "evidence": { "temperature": 82.1, "unit": "celsius" }
}
```

## Phạm vi

- Chốt contract version, authentication, endpoint/configuration ownership, timeout/retry policy và response semantics với EAMO.
- Gửi qua durable outbox; receiver xử lý idempotent theo `event_id`/idempotency key.
- Giữ Tenant/Project/Equipment/Device source và timestamp; không dùng payload fields làm quyền truy cập trong LiteBoard.
- Cung cấp receiver example hoặc contract test độc lập với EAMO production credentials.
- EAMO sở hữu alert nghiệp vụ nếu cần mở rộng, work order, assignment, technician và maintenance history.

## Nghiệm thu

- Một alert đi đến EAMO receiver; timeout, retry và duplicate delivery không tạo hai sự kiện logic.
- Schema version không tương thích có outcome rõ; outage kéo dài được quan sát và phục hồi sau khi receiver hoạt động lại.
- Event truy được ngược về alert, rule, Device và source telemetry; mọi dữ liệu nằm đúng Tenant/Project.
- Đo delivery latency, retry count, error rate, queue/outbox age và recovery time; không coi webhook HTTP 2xx đơn lẻ là bằng chứng downstream đã tạo nghiệp vụ thành công.

## Ngoài phạm vi

Không chuyển work order hoặc lịch sử bảo trì sang LiteBoard; không làm EAMO thành consumer duy nhất của các API/event LiteBoard.
