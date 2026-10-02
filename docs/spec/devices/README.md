# Tài liệu đặc tả Device, xác thực và onboarding

Thiết bị xác thực MQTT bằng Access Token. Provisioning qua HTTPS là bước tiếp theo để thiết bị tự đăng ký và nhận Access Token; các kế hoạch còn lại tập trung vào vận hành TLS, giới hạn tài nguyên và toàn vẹn telemetry.

## Các luồng công việc

| Luồng | Trạng thái | Phạm vi |
|---|---|---|
| [Access Token và provisioning](./access_token_progress/) | Phase 1–5 đã có implementation; HTTPS bootstrap Phase 6 chưa triển khai | Contract Access Token hiện tại và enrollment theo Project |
| [Kế hoạch tiếp theo](./future_plans/future_device_security_and_ingestion_plan.md) | Chưa triển khai, trừ khi tài liệu ghi rõ trạng thái khác | Nghiệm thu TLS runtime, xoay vòng chứng chỉ máy chủ, quota tài nguyên và toàn vẹn telemetry |

## Ranh giới phạm vi

- TLS server-auth trên MQTT `8883` là baseline transport riêng, tách khỏi HTTPS Access Token bootstrap đang được lập kế hoạch. Cấu hình repository hoặc kiểm tra certificate cục bộ chưa chứng minh broker runtime đã đạt.
- HTTPS bootstrap chỉ cấp Access Token. Thiết bị tiếp tục dùng token đó làm MQTT username và password theo contract hiện tại.
- TLS server-auth xác minh máy chủ; không tạo thêm loại credential hay phương thức đăng nhập cho Device.
- Device, credential và provisioning profile là dữ liệu tenant-owned thông qua Project. Implementation tương lai phải suy ra tenant context từ Project/profile đã được xác thực, không lấy từ tenant hoặc project ID do thiết bị gửi.

## Tài liệu

- [Access Token plan and status](./access_token_plan.md)
- [Access Token phase specifications](./access_token_progress/)
- [Kế hoạch TLS, quota và telemetry](./future_plans/future_device_security_and_ingestion_plan.md)
