# Phase 7: X.509 mTLS & Device Certificate Rotation

> Thuộc kế hoạch tương lai: [future_device_security_and_ingestion_plan.md](../future_device_security_and_ingestion_plan.md)  
> Tầng kiến trúc: **Mosquitto/EMQX + Laravel Control Plane + Edge Firmware**
> Phụ thuộc: [Phase 6](./phase-6-mqtt-basic-clientid-and-provisioning.md)  
> Trạng thái: `Future Plan`

---

## 1. Mục tiêu

Phase 6 đã tích hợp cấu hình broker và client TLS server-auth trên `8883`; kiểm tra cấu hình/chứng chỉ cục bộ đã qua, còn nghiệm thu broker end-to-end đang chờ Docker Engine. Phase 7 bổ sung xác thực thiết bị bằng chứng chỉ client và quy trình xoay vòng chứng chỉ, trong khi thiết bị Access Token/MQTT Basic tiếp tục dùng TLS server-auth.

## 2. Thiết kế dự kiến

### 2.1. Listener dành riêng cho thiết bị mTLS

- Giữ `8883` cho thiết bị xác thực bằng MQTT username/password qua TLS server-auth.
- Tạo listener TLS riêng `8884` cho thiết bị X.509 để không bắt các thiết bị hiện tại phải trình client certificate.
- Listener mTLS dùng `certfile`/`keyfile` của broker cùng `cafile` chứa CA cấp client certificate, bật `require_certificate true` và ánh xạ danh tính thiết bị theo CN hoặc fingerprint.
- Cả hai listener đều dùng TLS; không khôi phục `1883` hoặc `9001`.

### 2.2. Danh tính X.509 của thiết bị

- Credential X.509 lưu loại credential, Common Name, SHA-256 fingerprint, certificate PEM và thời hạn hết hạn. Laravel vẫn là nguồn xác thực quyền sở hữu Device và trạng thái credential.
- Thiết bị giữ client private key trong vùng lưu trữ bảo vệ phù hợp; chỉ client certificate được gửi tới broker. Không đưa private key vào Git hoặc firmware dùng chung.

### 2.3. Xoay vòng chứng chỉ

- Cho phép credential mới ở trạng thái `pending_rotation` trong thời gian chuyển tiếp để thiết bị có thể cài chứng chỉ mới trước khi thu hồi chứng chỉ cũ.
- Sau khi xác nhận kết nối bằng chứng chỉ mới hoặc hết thời gian chuyển tiếp, thu hồi credential cũ.
- Cảnh báo trước ngày hết hạn và ghi nhận kết quả xoay vòng để vận hành có thể xử lý thiết bị mất kết nối.

## 3. Checklist nghiệm thu Phase 7

- [ ] Broker từ chối client thiếu hoặc dùng client certificate không tin cậy trên listener mTLS.
- [ ] Quyền truy cập X.509 được ràng buộc đúng Device; chứng chỉ bị revoke không kết nối được.
- [ ] Thiết bị chuyển từ chứng chỉ cũ sang mới trong grace window; chứng chỉ cũ bị revoke đúng hạn.
- [ ] Listener Access Token vẫn xác minh server certificate qua TLS; không có MQTT plaintext listener.
