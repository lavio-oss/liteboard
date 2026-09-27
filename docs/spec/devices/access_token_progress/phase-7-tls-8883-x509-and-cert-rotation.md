# Phase 7: Bảo mật Đường truyền TLS 8883, Xác thực Chứng chỉ X.509 (`mTLS`) & Certificate Rotation

> Thuộc kế hoạch tương lai: [future_device_security_and_ingestion_plan.md](../future_device_security_and_ingestion_plan.md)  
> Tầng kiến trúc: **Mosquitto/EMQX TLS Layer + Laravel Control Plane + Edge Firmware**  
> Phụ thuộc: [Phase 6](./phase-6-mqtt-basic-clientid-and-provisioning.md)  
> Trạng thái: `Future Plan`

---

## 1. Mục tiêu của Phase 7

Nâng cấp bảo mật đường truyền và xác thực thiết bị lên chuẩn công nghiệp:
1. **TLS MQTT 8883**: Mã hóa toàn bộ lưu lượng MQTT giữa thiết bị và Broker bằng TLS 1.2/1.3 trên cổng `8883`.
2. **X.509 Certificate Authentication (`mTLS`)**: Xác thực hai chiều bằng chứng chỉ số (`client.crt` + `client.key`), loại bỏ nguy cơ lộ mật khẩu tĩnh.
3. **Certificate Rotation**: Hỗ trợ xoay vòng chứng chỉ sắp hết hạn mà không gây mất kết nối thiết bị (Zero-Downtime Rotation).

---

## 2. Thiết kế Kỹ thuật Chi tiết

### 2.1. TLS MQTT trên cổng `8883`

#### Cấu hình Listener `8883` trong `mosquitto.conf`
```conf
# Cổng 1883: Chỉ bind nội bộ trong mạng Docker cho telemetry-service
listener 1883 0.0.0.0

# Cổng 8883: Public MQTT over TLS cho các thiết bị ngoài thực địa
listener 8883 0.0.0.0
cafile /etc/mosquitto/certs/ca.crt
certfile /etc/mosquitto/certs/server.crt
keyfile /etc/mosquitto/certs/server.key
tls_version tlsv1.2
```
- Trên `ESP32`, thay `WiFiClient` bằng `WiFiClientSecure` và nạp Root CA (`wifiClient.setCACert(ROOT_CA_PEM)`), kết nối tới cổng `8883`.

---

### 2.2. Xác thực Thiết bị bằng Chứng chỉ Số X.509 (`mTLS`)

#### Hai phương thức kiểm tra X.509 của ThingsBoard áp dụng cho LiteBoard
1. **Xác thực theo Chuỗi CA + Common Name (`CN`)**:
   - Bật `require_certificate true` và `use_identity_as_username true` trên listener `8883` (hoặc listener `8884` chuyên cho mTLS).
   - Khi thiết bị trình chứng chỉ hợp lệ được ký bởi CA của hệ thống, Mosquitto tự động lấy trường `CN` (Common Name, ví dụ `device-019c0000-...`) gán vào biến `username` (`$1`).
2. **Xác thực theo SHA-256 Fingerprint (Thumbprint) của Chứng chỉ**:
   - Trong bảng `device_credentials`, lưu trữ:
     - `credential_type = 'x509'`
     - `cert_common_name` (`varchar(255)`)
     - `cert_sha256_fingerprint` (`char(64)`, unique)
     - `cert_pem` (`text`)
     - `expires_at` (`timestamptz` — trích xuất từ trường `validTo_time_t` của chứng chỉ bằng `openssl_x509_parse()` trong PHP).

---

### 2.3. Certificate Rotation (Xoay vòng Chứng chỉ Không Gián đoạn)

#### Bài toán
Nếu một chứng chỉ X.509 (hoặc Access Token) bị thay thế ngay lập tức trong DB trong khi thiết bị ngoài thực địa chưa kịp tải chứng chỉ mới qua OTA/Command, thiết bị sẽ bị mất kết nối vĩnh viễn và không thể nhận lệnh cập nhật nữa.

#### Giải pháp: Dual-Active Grace Window (Cửa sổ Chuyển giao Song song)
1. **Thay đổi ràng buộc bảng `device_credentials`**:
   - Thay `UNIQUE (device_id)` cứng bằng **Partial Unique Index** trên PostgreSQL:
     - Cho phép mỗi `device_id` có tối đa **1 bản ghi `active`** và **1 bản ghi `pending_rotation`** (có `rotation_grace_expires_at`, ví dụ 24 giờ).
2. **Luồng xoay vòng tự động (Zero-Downtime Rotation Flow)**:
   ```mermaid
   sequenceDiagram
       autonumber
       actor Admin as Project Admin
       participant CP as Laravel Control Plane
       participant DB as PostgreSQL (v_mqtt_users)
       participant ESP as Thiết bị (ESP32 / Edge)

       Admin->>CP: Yêu cầu Rotate Certificate / Credential (Grace window = 24h)
       CP->>DB: Tạo Credential mới (status = 'pending_rotation'), giữ Credential cũ ('active')
       Note over DB: View v_mqtt_users chấp nhận CẢ HAI<br/>('active' và 'pending_rotation' chưa hết hạn)
       CP->>ESP: Gửi lệnh MQTT Command (ROTATE_CREDENTIAL) chứa cert/token mới
       ESP->>ESP: Lưu cert/token mới vào phân vùng dự phòng & kết nối lại
       ESP->>CP: Gửi ACK / Telemetry bằng Credential mới
       CP->>DB: Chuyển Credential mới -> 'active', Credential cũ -> 'revoked'
   ```
3. **Cảnh báo Chứng chỉ sắp hết hạn**:
   - Scheduled Job chạy hàng ngày trên Laravel quét các `device_credentials` có `expires_at < now()->addDays(14)` để phát cảnh báo sớm trên Dashboard.

---

## 3. Checklist Nghiệm thu Phase 7

- [ ] Thiết bị kết nối thành công qua cổng `8883` với TLS 1.2/1.3; kết nối không có TLS vào cổng `8883` bị từ chối.
- [ ] Khi upload chứng chỉ X.509 PEM lên API `PUT .../credentials`, Laravel dùng `openssl_x509_parse()` tự động trích xuất đúng `CN`, `SHA-256 fingerprint` và ngày hết hạn `expires_at`.
- [ ] Trong thời gian `pending_rotation` (Grace Period), thiết bị có thể kết nối bằng cả chứng chỉ cũ lẫn chứng chỉ mới; ngay khi chứng chỉ mới được xác nhận sử dụng (hoặc hết hạn Grace Period), chứng chỉ cũ tự động chuyển sang `revoked`.
