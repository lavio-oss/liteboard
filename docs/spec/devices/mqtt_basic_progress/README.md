# Tiến độ MQTT Basic

## Trạng thái

**Kế hoạch tương lai — chưa triển khai.** Luồng credential Device hiện chỉ hỗ trợ Access Token. Contract hiện tại chưa có lựa chọn MQTT Basic, ràng buộc `clientId` hoặc MQTT provisioning transport.

## Phạm vi

Luồng này sẽ xác định:

- Credential MQTT Basic riêng cho từng Device: `clientId`, username và password.
- Cách lưu trữ, hiển thị một lần, rotate/revoke credential và phân quyền tenant/Project.
- Broker authentication và topic authorization theo từng Device.
- Ràng buộc chặt client ID với Device tại thời điểm CONNECT, nếu broker/auth adapter đã chọn thực thi được.
- MQTT provisioning (nếu cần) như một tính năng thiết kế riêng; không thuộc HTTPS Access Token bootstrap.

## Tài liệu

- [Thiết kế MQTT Basic và client ID](./mqtt-basic-and-client-id-plan.md)
- [Roadmap hardening tương lai](../future_plans/future_device_security_and_ingestion_plan.md)

## Điều kiện cần chốt trước khi triển khai

1. Chọn broker/authentication mechanism và chứng minh nó từ chối client ID sai ngay lúc CONNECT, không chỉ lúc kiểm tra topic ACL.
2. Chốt cách cấp, lưu, rotate, revoke credential; quyền API/UI; và tương thích ngược với thiết bị Access Token.
3. Chốt provisioning dùng HTTPS Access Token bootstrap hiện có hay MQTT transport và credential contract riêng.
4. Bổ sung kiểm thử tenant isolation, kết nối đồng thời và broker runtime vào kế hoạch triển khai.
