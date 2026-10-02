# Tiến độ Device: Access Token và provisioning

## Trạng thái

Access Token là credential đăng nhập MQTT duy nhất của Device. CRUD Device, quản lý credential, broker auth/ACL, telemetry và web UI đã có implementation trong Phase 1–5. Cấu hình MQTT TLS server-auth đã có; broker runtime và kiểm tra TLS end-to-end vẫn còn mở. HTTPS Device provisioning đang là kế hoạch, chưa triển khai.

## Danh sách Phase

| Phase | Phạm vi | Trạng thái |
|---|---|---|
| [Phase 1](./phase-1-database-and-sql-views.md) | Device schema và PostgreSQL MQTT views | Hoàn thành theo checklist |
| [Phase 2](./phase-2-laravel-control-plane.md) | Laravel Device API và vòng đời credential | Hoàn thành theo checklist |
| [Phase 3](./phase-3-mqtt-broker-mosquitto-go-auth.md) | Mosquitto Access Token auth và Device ACL | Đã có implementation; cần xác minh lại runtime broker |
| [Phase 4](./phase-4-telemetry-data-plane-and-edge.md) | Telemetry service, simulator và ESP32 | Luồng chính đã có; TLS broker end-to-end còn mở |
| [Phase 5](./phase-5-nuxt-web-ui.md) | UI Device và credential | Hoàn thành theo checklist |
| [Phase 6](./phase-6-https-access-token-provisioning.md) | HTTPS bootstrap theo Project, tạo Device và trả Access Token | Kế hoạch; chưa triển khai |

## Việc tiếp theo

1. Xác minh broker thật qua TLS `8883`, Access Token auth/ACL và các trường hợp từ chối; ghi kết quả runtime trước khi đánh dấu các mục Phase 3–4 hoàn tất end-to-end.
2. Triển khai Phase 6 theo thứ tự: profile theo Project, HTTPS bootstrap an toàn/idempotent, rồi cập nhật simulator và ESP32 để lưu và dùng Access Token đã cấp.
3. Kiểm thử tenant/project isolation, retry, quota, rotate/revoke và luồng MQTT thực tế trước khi đánh dấu Phase 6 hoàn tất.
4. Theo dõi [TLS server certificate, quota và telemetry integrity](../future_plans/future_device_security_and_ingestion_plan.md) trong các phase tiếp theo.
