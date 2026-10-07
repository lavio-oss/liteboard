# Tài liệu đặc tả Device, xác thực và onboarding

Device thuộc một Project trong Tenant. MQTT dùng Access Token riêng cho từng Device; provisioning là một cách đăng ký Device vào Project và vẫn cấp đúng loại token này. Hai luồng đang có được mô tả tại [Device Access Token: cơ sở và luồng hoạt động](./devices_access_token.md).

## Ranh giới

- MQTT TLS trên `8883` và HTTPS provisioning là hai kết nối riêng. HTTPS bootstrap không thay credential MQTT; nó cấp Access Token để client dùng làm username và password.
- Device được tạo thủ công ở trạng thái duyệt mặc định `approved`. Device từ provisioning nhận token ngay nhưng bắt đầu ở `pending`; MQTT auth view chỉ cho phép Device `approved`.
- Provisioning profile thuộc Project. Bootstrap tra Tenant/Project từ profile đã xác thực, không tin Tenant/Project ID trong request của thiết bị.
- Quota tạo Device được dùng chung bởi hai luồng và đếm tất cả Device hiện có trong Project.

## Trạng thái triển khai

Theo đối chiếu repo ngày 2026-10-07, Device thủ công, Access Token, MQTT auth/ACL, provisioning, duyệt Device và quota tạo Device theo Project đã có implementation. Mosquitto TLS `8883` được cấu hình cho môi trường local; việc xoay chứng chỉ trên topology production chưa được nghiệm thu. `telemetry-service` hiện chưa lưu telemetry bền vững, kiểm tra schema hoặc khử trùng lặp message ID. Xem [roadmap hardening và ingestion](./access_token_dev_plan/future_device_security_and_ingestion_plan.md) để biết phần nào đã làm và phần nào còn mở.

## Tài liệu

- [Device Access Token: cơ sở và luồng hoạt động](./devices_access_token.md)
- [Kế hoạch hardening và ingestion](./access_token_dev_plan/future_device_security_and_ingestion_plan.md)
- [MQTT TLS server certificate](./access_token_dev_plan/phase-7-mqtt-tls-server-certificate.md)
- [Quota và rate limiting](./access_token_dev_plan/phase-8-quotas-and-rate-limiting.md)
- [Schema validation và replay protection](./access_token_dev_plan/phase-9-schema-validation-and-replay-protection.md)
- [Roadmap telemetry công nghiệp end-to-end](../industrial-telemetry/README.md)
