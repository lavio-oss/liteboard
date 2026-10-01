# Tài liệu đặc tả Device, xác thực và onboarding

Thư mục này tách riêng luồng Access Token hiện có, kế hoạch onboarding HTTPS, hướng MQTT Basic trong tương lai và các kế hoạch hardening tiếp theo.

## Các luồng công việc

| Luồng | Trạng thái | Phạm vi |
|---|---|---|
| [Access Token](./access_token_progress/) | Phase 1–5 đã có implementation; còn thiếu xác minh TLS broker end-to-end; HTTPS bootstrap đang là kế hoạch | Contract Access Token hiện tại và enrollment theo Project |
| [MQTT Basic](./mqtt_basic_progress/) | Kế hoạch tương lai, chưa triển khai | Credential MQTT `clientId`/username/password và ràng buộc danh tính tại broker |
| [Kế hoạch tương lai](./future_plans/) | Chưa triển khai, trừ khi tài liệu ghi rõ trạng thái khác | X.509/mTLS, quota tài nguyên, kiểm tra telemetry và chống replay/deduplication |

## Ranh giới phạm vi

- TLS server-auth trên MQTT `8883` là baseline transport riêng, tách khỏi HTTPS Access Token bootstrap đang được lập kế hoạch. Cấu hình repository hoặc kiểm tra certificate cục bộ chưa chứng minh broker runtime đã đạt.
- HTTPS bootstrap của Access Token chỉ cấp Access Token; không bao gồm MQTT Basic provisioning, strict `clientId` binding hoặc X.509.
- MQTT Basic là luồng riêng và hiện chưa triển khai.
- Device, credential và provisioning profile là dữ liệu tenant-owned thông qua Project. Implementation tương lai phải suy ra tenant context từ Project/profile đã được xác thực, không lấy từ tenant hoặc project ID do thiết bị gửi.

## Tài liệu

- [Access Token plan and status](./access_token_plan.md)
- [Access Token phase specifications](./access_token_progress/)
- [MQTT Basic plan](./mqtt_basic_progress/)
- [Future hardening roadmap](./future_plans/future_device_security_and_ingestion_plan.md)
