# Access Token và MQTT

Access Token là credential MQTT duy nhất của Device. Provisioning profile chỉ xác thực quyền đăng ký một Device vào Project; nó không thay thế Access Token. Device thủ công được duyệt ngay theo mặc định, còn Device provisioning nhận token trước rồi chờ Project admin duyệt trước khi được MQTT broker cấp quyền.

Tài liệu chuẩn về mô hình, tenant boundary, API contract và hai luồng hiện hành nằm tại [Access Token: cơ sở và luồng hoạt động](./access_token/README.md). Các hạng mục tương lai về TLS production, quota kết nối và toàn vẹn telemetry nằm trong [kế hoạch hardening](./future_plans/future_device_security_and_ingestion_plan.md).
