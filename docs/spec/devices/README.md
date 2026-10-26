# Device, xác thực và onboarding

Device thuộc một Project trong Tenant. MQTT dùng Access Token riêng cho từng Device; provisioning là một cách đăng ký Device vào Project và vẫn cấp đúng loại token này. Hai luồng đang có được mô tả tại [Device Access Token: cơ sở và luồng hoạt động](./current/devices_access_token.md).

## Ranh giới

- MQTT TLS trên `8883` và HTTPS provisioning là hai kết nối riêng. HTTPS bootstrap không thay credential MQTT; nó cấp Access Token để client dùng làm username và password.
- Device được tạo thủ công ở trạng thái duyệt mặc định `approved`. Device từ provisioning nhận token ngay nhưng bắt đầu ở `pending`; MQTT auth view chỉ cho phép Device `approved`.
- Provisioning profile thuộc Project. Bootstrap tra Tenant/Project từ profile đã xác thực, không tin Tenant/Project ID trong request của thiết bị.
- Quota tạo Device được dùng chung bởi hai luồng và đếm tất cả Device hiện có trong Project.
- Dữ liệu telemetry sau khi vào hệ thống (lưu trữ, kiểm tra theo Profile, trace, rule/alert) thuộc [industrial-telemetry](../industrial-telemetry/README.md). Xác thực người dùng thuộc [auth](../auth/README.md).

## Hiện trạng

Theo đối chiếu repo ngày 2026-10-18 đã có implementation cho: Device thủ công, Access Token, MQTT auth/ACL, provisioning, duyệt Device và quota tạo Device theo Project. Mosquitto TLS `8883` được cấu hình cho môi trường local; việc xoay chứng chỉ trên topology production chưa được nghiệm thu.

Phía telemetry, `telemetry-service` đã lưu telemetry bền vững vào PostgreSQL, kiểm tra payload theo Profile Version và khử trùng lặp theo `(device_id, message_id)`; xem [telemetry flow hiện hành](../industrial-telemetry/current/telemetry-flow.md). Quota kết nối đồng thời và rate limit theo Device vẫn chưa có.

| Tài liệu | Nội dung |
| --- | --- |
| [Device Access Token: cơ sở và luồng hoạt động](./current/devices_access_token.md) | Khái niệm và ranh giới, luồng thêm Device thủ công, luồng provisioning, bảo vệ transport, cấu trúc dữ liệu và điểm vào mã nguồn |

## Kế hoạch

| Phase | Phạm vi | Trạng thái | Tài liệu |
| --- | --- | --- | --- |
| Roadmap | Thứ tự các phase hardening và ingestion | Kế hoạch | [Roadmap](./plan/future_device_security_and_ingestion_plan.md) |
| Phase 7 | MQTT TLS server-auth và xoay vòng chứng chỉ máy chủ | TLS local đã có; nghiệm thu production còn mở | [Phase 7](./plan/phase-7-mqtt-tls-server-certificate.md) |
| Phase 8 | Giới hạn kích thước gói tin, số kết nối và tốc độ gửi theo Device | Một phần: quota tạo Device và giới hạn payload 64 KiB ở `telemetry-service` đã có; quota kết nối và rate limit theo Device chưa | [Phase 8](./plan/phase-8-quotas-and-rate-limiting.md) |
| Phase 9 | Kiểm tra schema telemetry và chống replay/trùng lặp | Phần kiểm tra schema và khử trùng lặp đã làm theo thiết kế khác (Profile Version và PostgreSQL, không dùng Redis); tài liệu cần rà lại | [Phase 9](./plan/phase-9-schema-validation-and-replay-protection.md) |
| Phase 10 | Playground MQTT plaintext `1883` | Một phần: broker `mosquitto-plain`, cờ `allow_plaintext` theo từng thiết bị, contract, UI và firmware đã có; còn biên dịch firmware, downlink qua bridge, đo tải auth và firewall production | [Phase 10](./plan/phase-10-mqtt-plaintext-playground.md) |

Roadmap end-to-end của telemetry công nghiệp: [industrial-telemetry](../industrial-telemetry/README.md).
