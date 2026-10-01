# MQTT Basic credential và ràng buộc client ID

> Trạng thái: Kế hoạch tương lai; tài liệu này không có nghĩa là code đã được triển khai.  
> Contract hiện tại: [Access Token plan](../access_token_plan.md).

## 1. Mục tiêu

Hỗ trợ thiết bị MQTT cần credential riêng cho `clientId`, username và password, đồng thời giữ nguyên Access Token mode cho thiết bị hiện tại.

Luồng này tách biệt với [HTTPS Access Token bootstrap](../access_token_progress/phase-6-https-access-token-provisioning.md). Nếu cần MQTT Basic provisioning qua MQTT, phải chốt contract và rollout riêng.

## 2. Ranh giới hiện tại

- `device_credentials` và broker auth path hiện hỗ trợ `access_token`.
- Client hiện dùng Access Token làm cả MQTT username và password.
- `clientId` của simulator và ESP32 chưa được lưu như một ràng buộc với credential Device.
- Strict client-ID enforcement hiện không thuộc tiêu chí nghiệm thu onboarding bằng Access Token.

Trước khi triển khai, cần kiểm tra lại code, broker artifact và cấu hình. Các ý trên mô tả baseline trong tài liệu, không khẳng định trạng thái runtime tương lai.

## 3. Các quyết định thiết kế cần chốt

### Credential contract

Định nghĩa credential type như `mqtt_basic` và các trường thuộc credential đó. Các trường dự kiến gồm username duy nhất, password hash và client ID mong đợi. Không lưu password dạng plaintext. Chốt client ID là bắt buộc, do platform sinh hay được cung cấp qua enrollment có kiểm soát.

### Ranh giới Device và tenant

Credential vẫn là tenant-owned data theo quan hệ Device → Project → Tenant. API quản lý phải dùng tenant context, Project route binding và policy hiện có. Thiết bị không được chọn Project/Tenant trong MQTT credential hoặc bootstrap body.

### Enforcement tại broker

Broker phải xác thực credential type đã chọn và áp dụng cùng ranh giới topic ACL theo Device như Access Token mode. Trước khi cam kết strict client-ID binding, cần chứng minh broker/auth artifact đã pin từ chối client ID sai ngay tại CONNECT. Phải kiểm tra PostgreSQL `mosquitto-go-auth` và query placeholders trên đúng artifact; việc client ID có mặt khi xét ACL không chứng minh nó được ràng buộc lúc CONNECT.

### UI và API quản lý

Định nghĩa cách người dùng được phép tạo, xem secret một lần, rotate và revoke MQTT Basic credential. Giữ các thao tác Access Token hiện có và hiển thị credential type mà không làm lộ password đã lưu. Chốt API resource trước khi đổi UI.

### Quan hệ với provisioning

Luồng Access Token dùng HTTPS bootstrap và trả Access Token. Cần quyết định riêng thiết bị MQTT Basic sẽ:

1. dùng HTTPS flow đó với phần mở rộng credential type; hoặc
2. dùng MQTT provisioning topics và một bootstrap identity riêng.

Không tự ý thêm `credential_type` hoặc tái dùng response shape của Access Token trước khi thống nhất credential và broker contract.

## 4. Yêu cầu bảo mật và nghiệm thu

- Password sai, credential bị revoke, Device/Project/Tenant bị disable và client ID không khớp đều bị từ chối.
- Credential của một Device không được xác thực hoặc publish/subscribe thay Device, Project hay Tenant khác.
- Rotate credential vô hiệu hóa password cũ theo chính sách chuyển đổi đã ghi rõ.
- Có chính sách minh bạch cho client kết nối đồng thời; client ID trùng không gây reconnect loop mất kiểm soát.
- Không ghi secret vào application, broker hoặc telemetry log.
- Runtime test dùng đúng broker/auth artifact đã pin, kiểm tra cả CONNECT và topic ACL.

## 5. Thứ tự triển khai

1. Tạo proof of concept broker cho enforcement client ID tại CONNECT.
2. Chốt credential/provisioning contract, gồm rotation và chính sách session đồng thời.
3. Thêm schema forward-only và backend management có tenant isolation cùng test tập trung.
4. Cập nhật broker authentication/ACL mà không đổi hành vi Access Token.
5. Bổ sung API/UI quản lý credential và ví dụ client.
6. Xác minh các trường hợp thành công/thất bại ở broker runtime trước khi đánh dấu đã triển khai.
