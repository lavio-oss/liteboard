# IT-4 — REST, WebSocket và message trace

> Trạng thái: kế hoạch; chưa có query API, authorized telemetry broadcast hay end-to-end message inspector.

## Mục tiêu

Giúp developer xem lịch sử telemetry, nhận sample mới và lần theo kết quả xử lý bằng một `message_id`.

## Phạm vi

- REST read theo Tenant/Project/Device với `from`, `to`, metric keys, page/limit và basic aggregation.
- WebSocket subscription chỉ cho user đã được authorize trên đúng Tenant/Project/Device; revalidate quyền khi cần và giới hạn buffer cho subscriber chậm.
- Message trace theo correlation/message ID qua receive, auth/topic scope, decode, validation, persistence, rule evaluation và broadcast.
- Trả outcome/reason code có ích nhưng không làm lộ Access Token, provisioning secret hoặc dữ liệu bị giới hạn.
- PostgreSQL là storage ban đầu; TimescaleDB chỉ được chọn sau khi query/ingest benchmark chỉ ra bottleneck.

## Nghiệm thu

- REST trả sample trong đúng khoảng thời gian và metric; sai/cross-tenant scope bị từ chối.
- Hai subscriber được phép nhận WebSocket event; user ngoài Project không nhận được event; reconnect không làm nhân bản logical sample.
- Một `message_id` truy ra được stage outcome và receive/store/broadcast timestamps.
- Đo end-to-end và từng stage p50/p95/p99 trên môi trường pilot. Chỉ đặt latency SLO sau khi có baseline; không dùng số minh họa như kết quả.
- WebSocket subscriber chậm/disconnect không làm Data Plane tăng buffer vô hạn.

## Ngoài phạm vi

Dashboard builder tổng quát, arbitrary query DSL và HA WebSocket cluster chưa nằm trong slice đầu.
