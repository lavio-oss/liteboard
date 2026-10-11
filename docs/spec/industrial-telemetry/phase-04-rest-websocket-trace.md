# IT-4 — REST, WebSocket và message trace

> Trạng thái: kế hoạch; chưa có telemetry query API, authorized telemetry WebSocket hay end-to-end message inspector.

## Mục tiêu

Cho người dùng có quyền xem giá trị mới nhất và lịch sử telemetry, nhận thay đổi gần thời gian thực và điều tra processing outcome của một message.

## Phạm vi và contract

- Bắt đầu bằng PostgreSQL. Cung cấp latest telemetry riêng với history query để màn hình Device đọc trạng thái hiện tại nhanh mà không truy vấn toàn bộ lịch sử.
- REST được scope qua Tenant → Project → Device và policy hiện hành. Đề xuất routes:

```text
GET /api/tenants/{tenant}/projects/{project}/devices/{device}/telemetry/latest
GET /api/tenants/{tenant}/projects/{project}/devices/{device}/telemetry
GET /api/tenants/{tenant}/projects/{project}/devices/{device}/messages/{message_id}/trace
```

- History nhận `from`, `to`, danh sách metric keys, aggregation interval và page/cursor có giới hạn. Contract phải chốt time zone, inclusive/exclusive boundary, thứ tự ổn định và hành vi khi khoảng thời gian hoặc số điểm vượt giới hạn. Không thêm query DSL tổng quát ở slice đầu.
- Phân biệt `occurred_at` của thiết bị và `received_at` của server. Latest được xác định theo event-time đã được chấp nhận, có tie-breaker ổn định; sample đến trễ không ghi đè giá trị mới hơn. Clock-skew policy thuộc IT-3.
- WebSocket chỉ cho user được authorize trên đúng Tenant/Project/Device. Revalidate quyền khi membership thay đổi; giới hạn buffer và drop/disconnect policy cho subscriber chậm phải rõ.
- Phát telemetry event sau DB commit. Mỗi event có `device_id`, `message_id`, event sequence hoặc identity ổn định, `occurred_at`, `received_at`, metric values đã được phép hiển thị và state liên quan. Không chứa credential hoặc unrestricted raw payload.
- Reconnect dùng REST để lấy khoảng dữ liệu thiếu; client deduplicate phần giao giữa history và realtime theo identity sample. Reconnect không được tạo sample mới.
- Trace tìm theo cặp Device + `message_id`; scope kiểm tra quyền trước khi đọc. Message không có ID hợp lệ vẫn có server-generated processing ID để điều tra.
- Persist timestamps/outcomes cho receive, topic/device/project resolution, decode, validation, commit, rule enqueue và broadcast/delivery. Không giả định broker ACL đã cung cấp MQTT publisher identity cho callback nếu integration chưa truyền identity đó.

## Nghiệm thu

- REST latest và history trả đúng Device/Project, metric, thời gian và aggregation; query cross-tenant/project bị từ chối. Kiểm tra giới hạn dữ liệu lớn và pagination có thứ tự ổn định.
- Late sample không làm latest lùi thời gian; tie cùng timestamp xử lý xác định.
- User thuộc Project nhận WebSocket event; user ngoài Project không nhận được event. Thu hồi quyền khi đang subscribe được kiểm tra.
- Subscriber chậm, disconnect và reconnect không làm buffer tăng vô hạn; client dùng REST recovery mà không nhân đôi logical sample.
- Trace tra được sample theo Device + `message_id`, stage outcome và timestamps; malformed message tra được bằng processing ID; secrets không xuất hiện.
- Đo end-to-end và từng stage p50/p95/p99 trên môi trường pilot. Chỉ chốt SLO sau khi có baseline thực tế.

## Ngoài phạm vi

Dashboard builder tổng quát, arbitrary query DSL, multi-entity analytics và HA WebSocket cluster không thuộc slice đầu. TimescaleDB/partitioning chỉ được cân nhắc khi benchmark chỉ ra bottleneck.
