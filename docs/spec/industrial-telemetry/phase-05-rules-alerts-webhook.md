# IT-5 — Rule, alert và webhook

> Trạng thái: kế hoạch; chỉ bắt đầu sau khi schema-valid telemetry đã persist và có test/query fixture.

## Mục tiêu

Chuyển điều kiện thiết bị công nghiệp thành một alert có lịch sử và event retryable.

## Vertical rule đầu tiên

Rule mẫu (minh họa contract, chưa phải API/YAML đã chốt):

```yaml
name: high_temperature
profile: motor-v1
when:
  field: temperature
  operator: greater_than
  value: 80
  for: 30s
then:
  create_alert:
    severity: CRITICAL
  enqueue_webhook: true
```

Rule gắn với Project/Profile; mỗi evaluation giữ source Device, Equipment, schema version và message. `for: 30s` phải chốt dùng event-time hay server receive-time, xử lý sample bị trễ/mất và recovery/hysteresis trước implementation.

## Phạm vi

- Bắt đầu bằng scalar comparator và duration; không execute arbitrary tenant code.
- Tạo alert có lifecycle, severity, source telemetry/message và dedupe key; định nghĩa khi nào chuyển active/recovered.
- Ghi outbound event vào durable outbox/queue trước khi giao webhook; retry có giới hạn, backoff, delivery history và idempotency.
- Giới hạn endpoint webhook theo policy quản trị; validate URL và bảo vệ outbound request khỏi SSRF.

## Nghiệm thu

- Với simulator gửi nhiệt độ trên `80 °C` đủ 30 giây, một alert `CRITICAL` xuất hiện đúng Equipment/Device.
- Message retry hoặc worker restart không sinh alert/event trùng; dưới ngưỡng hoặc recovery theo contract đưa alert về trạng thái đúng.
- Webhook mock chậm/5xx được retry và ghi lịch sử; tenant/project khác không thể tạo hay đọc rule/alert này.
- Báo cáo evaluation latency, delivery success/failure, retry và recovery.

## Ngoài phạm vi

ML anomaly detection, predictive maintenance và tạo work order thuộc ngoài slice này; EAMO xử lý maintenance workflow.
