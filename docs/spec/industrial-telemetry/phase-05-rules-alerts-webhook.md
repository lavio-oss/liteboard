# IT-5 — Rule, alert và webhook

> Trạng thái: kế hoạch; bắt đầu sau khi IT-3 có persisted telemetry và replayable evaluation work.

## Mục tiêu

Biến telemetry công nghiệp thành alert có vòng đời, evidence, audit history và event giao ra ngoài theo cách có thể retry.

## Rule pilot

```yaml
code: high_temperature
profile: motor-v1
when:
  field: temperature
  operator: greater_than
  value: 80
  duration: 30s
  time_basis: received_at
  max_sample_gap: <pilot-defined-duration>
then:
  create_or_update_alert:
    severity: CRITICAL
clear:
  field: temperature
  operator: less_than_or_equal
  value: <pilot-defined-recovery-threshold>
  duration: <pilot-defined-recovery-duration>
```

Các ngưỡng recovery, duration và sample gap phải được xác nhận với pilot; ví dụ không phải giá trị kỹ thuật đã chốt. Runtime ban đầu chỉ hỗ trợ scalar comparator và duration giới hạn; không execute arbitrary tenant code.

## Semantics bắt buộc

- Rule thuộc Project/Profile và mỗi evaluation ghi source Device, Equipment association tại thời điểm sample, Profile Version, rule revision và source telemetry identity.
- Dùng server `received_at` cho duration pilot để tránh phụ thuộc đồng hồ thiết bị. `occurred_at` vẫn là evidence. Sample thiếu hoặc gap dài hơn giới hạn đặt lại điều kiện liên tục; sample trễ không được tua ngược state.
- Định nghĩa điều kiện trigger, sample gap, restart/replay, rule revision đang áp dụng, hysteresis và clear trước implementation. Evaluation phải xác định khi nhận cùng event nhiều lần.
- Mỗi cặp Device + rule chỉ có một alert active tại một thời điểm. Retry cập nhật cùng alert; không tạo alert logic mới. Lưu active, cleared/recovered, acknowledged/unacknowledged như hai trục trạng thái độc lập. Acknowledge ghi actor/time nhưng không tự clear alert.
- Alert history giữ severity transitions, opened/last-seen/cleared/acknowledged timestamps, rule revision, source sample/message và evidence đã giới hạn. Chốt cách xử lý khi Device đổi Equipment hoặc Rule/Profile Version bị archive.
- Transaction cập nhật evaluation state, tạo/cập nhật alert và ghi durable outbound event/outbox cùng nhau. Dispatcher/webhook xử lý sau commit để HTTP timeout không rollback telemetry hoặc làm mất event.
- Retry có backoff, giới hạn, delivery history, idempotency và trạng thái exhausted/dead-letter có thể quan sát. HTTP 2xx xác nhận receiver đã nhận request theo contract, không mặc định nghiệp vụ downstream đã xong.
- Endpoint webhook thuộc Tenant/Project theo policy; validate destination và chặn SSRF, gồm redirect, DNS/IP resolution và private network access. Secrets có quyền đọc/rotate riêng và không xuất hiện trong logs.

## Nghiệm thu

- Simulator gửi nhiệt độ trên 80 °C liên tục theo duration và gap policy đã chốt tạo đúng một alert `CRITICAL` trên Device/Equipment tương ứng.
- Duplicate message, evaluation retry, replay và worker restart không nhân đôi alert hoặc outbox event.
- Mẫu thiếu, gap dài, dữ liệu trễ, recovery/hysteresis và rule revision mới cho kết quả xác định; acknowledge không đánh dấu recovery.
- Webhook mock chậm, timeout, 5xx, duplicate delivery và retry exhaustion lưu lịch sử; worker phục hồi gửi tiếp mà không mất event.
- User khác Tenant/Project không thể tạo, xem, acknowledge hay clear rule/alert ngoài scope. Không có arbitrary code execution.
- Đo evaluation latency, outbox age, delivery success/failure, retry và recovery.

## Ngoài phạm vi

ML anomaly detection, predictive maintenance và work-order workflow không thuộc phase này. EAMO sở hữu maintenance workflow.
