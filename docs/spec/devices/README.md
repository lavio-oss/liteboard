# Tài liệu đặc tả Device, xác thực và onboarding

Device thuộc một Project trong Tenant. MQTT dùng Access Token riêng cho từng Device; provisioning là một cách đăng ký Device vào Project và vẫn cấp đúng loại token này. Hai luồng đang có được mô tả tại [Access Token: cơ sở và luồng hoạt động](./access_token/README.md).

## Ranh giới

- MQTT TLS trên `8883` và HTTPS provisioning là hai kết nối riêng. HTTPS bootstrap không thay credential MQTT; nó cấp Access Token để client dùng làm username và password.
- Device được tạo thủ công ở trạng thái duyệt mặc định `approved`. Device từ provisioning nhận token ngay nhưng bắt đầu ở `pending`; MQTT auth view chỉ cho phép Device `approved`.
- Provisioning profile thuộc Project. Bootstrap tra Tenant/Project từ profile đã xác thực, không tin Tenant/Project ID trong request của thiết bị.
- Quota tạo Device được dùng chung bởi hai luồng và đếm tất cả Device hiện có trong Project.

## Tài liệu

- [Access Token: cơ sở và luồng hoạt động](./access_token/README.md)
- [Kế hoạch hardening và ingestion](./future_plans/future_device_security_and_ingestion_plan.md)
- [MQTT TLS server certificate](./future_plans/phase-7-mqtt-tls-server-certificate.md)
- [Quota và rate limiting](./future_plans/phase-8-quotas-and-rate-limiting.md)
- [Schema validation và replay protection](./future_plans/phase-9-schema-validation-and-replay-protection.md)
