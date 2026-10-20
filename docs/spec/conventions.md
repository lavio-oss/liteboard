# Đặc tả LiteBoard

Thư mục này chứa đặc tả theo từng mảng nghiệp vụ. Mọi mảng có cùng một cấu trúc để biết ngay đâu là việc đã làm và đâu là việc sắp làm:

```text
<mảng>/
├── README.md   phạm vi, ranh giới, bản đồ tài liệu và trạng thái
├── current/    hiện trạng: mô tả đúng code đang chạy, có ngày đối chiếu
└── plan/       kế hoạch: phase/task chưa hoặc đang triển khai
```

## Quy ước

- **`current/` chỉ ghi điều đã có trong code.** Mỗi tài liệu ghi ngày đối chiếu và không mô tả tính năng chưa làm như đã có. Phần còn thiếu của một tài liệu hiện trạng được liệt kê riêng ở cuối tài liệu đó.
- **`plan/` chứa phase hoặc task chưa xong.** Mỗi file mở đầu bằng khối trạng thái (Trạng thái, Thuộc kế hoạch, Tầng kiến trúc, Phụ thuộc) và kết thúc bằng checklist nghiệm thu.
- **Vòng đời:** việc mới được viết thành file trong `plan/`. Khi làm xong và nghiệm thu, nội dung chuyển vào `current/` rồi file trong `plan/` bị xóa (cách IT-1 đến IT-5 đã được gộp vào [telemetry-flow.md](./industrial-telemetry/current/telemetry-flow.md)).
- Thư mục `plan/` chỉ tạo khi thật sự có kế hoạch; mảng chưa có kế hoạch thì không có thư mục này.
- Mỗi mảng có một `README.md` nêu phạm vi và ranh giới với các mảng khác. Cập nhật README khi thêm, xóa hoặc đổi trạng thái tài liệu.

## Các mảng

| Mảng | Phạm vi | Hiện trạng (`current/`) | Kế hoạch (`plan/`) |
| --- | --- | --- | --- |
| [tenant](./tenant/README.md) | Tenant, Project, Asset master data, quản lý tenant | 2 tài liệu | Chưa có |
| [auth](./auth/README.md) | Xác thực người dùng, phân quyền, xác thực giữa backend và telemetry-service | 3 tài liệu | Chưa có |
| [devices](./devices/README.md) | Device, Access Token, MQTT auth/ACL, provisioning, quota tạo Device | 1 tài liệu | Roadmap hardening và Phase 7, 8, 9 |
| [industrial-telemetry](./industrial-telemetry/README.md) | Ingest, Profile/schema, presence, REST/WebSocket, rule/alert/webhook | Telemetry flow | Phase 6: giao event cho EAMO qua hàng đợi |
| [mqtt-ids](./mqtt-ids/README.md) | Nghiên cứu IDS cho MQTT (POC) | Chưa có | Kế hoạch nghiên cứu |

Thứ tự đọc gợi ý cho người mới: tenant, auth, devices, industrial-telemetry.

## Tài liệu liên quan ngoài `spec/`

- [Kiến trúc](../architecture.md) và [kế hoạch tổng thể](../plan.md).
- [Data contract integrity](../DATA_CONTRACT_INTEGRITY_RULE.md) và [Request handling](../Request_Handling.md): quy tắc bắt buộc khi đổi schema, API hoặc xử lý request.
- [Cấu trúc module backend](../modules.md).
