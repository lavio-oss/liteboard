# ESP32: cài đặt LiteBoardSetup và firmware

`LiteBoardSetup` là thư viện dùng chung của hai sketch LiteBoard trong `ESP32/`. Thư viện gom phần kết nối Wi-Fi và cấu hình MQTT qua TLS đã xác minh CA. Sketch Access Token và Provisioning vẫn tự quản lý luồng nghiệp vụ riêng.

## Yêu cầu

- Arduino core cho ESP32 và board phù hợp với thiết bị của bạn.
- Thư viện `PubSubClient` cài từ Arduino Library Manager hoặc Arduino CLI.
- Thư viện local `LiteBoardSetup` nằm tại `ESP32/libraries/LiteBoardSetup`.
- Mạng Wi-Fi truy cập được broker MQTT; sketch Provisioning cần thêm HTTPS endpoint và đồng bộ thời gian qua NTP.

`WiFi`, `WiFiClientSecure`, `HTTPClient`, `Preferences` và `cJSON` được cung cấp bởi ESP32 Arduino core. `PubSubClient` là dependency bên ngoài và cũng được khai báo trong `library.properties`.

## Cài thư viện

### Arduino IDE

1. Cài ESP32 Arduino core và chọn đúng board trong Board Manager.
2. Cài `PubSubClient` trong Library Manager.
3. Chép thư mục `ESP32/libraries/LiteBoardSetup` vào thư mục `libraries` của Arduino Sketchbook. Có thể xem đường dẫn Sketchbook trong Arduino IDE Preferences.
4. Khởi động lại Arduino IDE để nó nhận `LiteBoardSetup`.
5. Mở một sketch từ thư mục tương ứng: `ESP32/AccessToken/AccessToken.ino` hoặc `ESP32/Provisioning/Provisioning.ino`.

### Arduino CLI

Cài dependency `PubSubClient` một lần:

```powershell
arduino-cli lib install PubSubClient
```

Khi biên dịch, truyền thư mục thư viện local bằng `--libraries` và thay FQBN bằng board của bạn:

```powershell
arduino-cli compile --libraries .\ESP32\libraries --fqbn <your-board-fqbn> .\ESP32\AccessToken
arduino-cli compile --libraries .\ESP32\libraries --fqbn <your-board-fqbn> .\ESP32\Provisioning
```

## Cấu hình Access Token thông thường

1. Sao chép `ESP32/AccessToken/device_config.h.example` thành `device_config.h`.
2. Điền SSID/password Wi-Fi, MQTT host/port, Project ID, Device ID, Access Token và telemetry topic. MQTT host phải khớp DNS hoặc IP trong SAN của chứng chỉ broker. LiteBoard yêu cầu MQTT username và password đều bằng Access Token.
3. Sao chép `mqtt_ca_cert.h.example` thành `mqtt_ca_cert.h`, rồi thay nội dung placeholder bằng **public CA certificate** đã ký chứng chỉ MQTT broker.
4. Mở `AccessToken.ino`, biên dịch và nạp firmware.

Trong môi trường local, `infrastructure/mosquitto/generate-dev-certs.ps1` có thể tạo certificate và file CA header cho sketch. Script từ chối ghi đè các certificate đã tồn tại; nếu đã có certificate/CA trong workspace, giữ nguyên chúng và không chạy lại script.

## Cấu hình HTTPS Provisioning

1. Tạo provisioning profile cho Project và bật profile sau khi đã lưu key/secret an toàn.
2. Sao chép `ESP32/Provisioning/provisioning_config.h.example` thành `provisioning_config.h`; điền Wi-Fi, provisioning URL, key, secret, tên Device và firmware version.
3. Đảm bảo hostname trong URL phân giải tới HTTPS endpoint và khớp SAN của certificate. Sketch đồng bộ thời gian qua NTP trước khi thiết lập TLS.
4. Có thể smoke test enrollment trên mạng LAN bằng HTTP nếu backend chạy với `APP_ENV=local` và `DEVICE_PROVISIONING_ALLOW_INSECURE_HTTP=true`; cách này không kiểm tra xác minh certificate. Để kiểm tra HTTPS, dùng endpoint HTTPS có hostname/SAN và CA mà firmware tin cậy, chẳng hạn môi trường staging chạy sau Nginx.
5. Cấu hình `ESP32/AccessToken/mqtt_ca_cert.h` như ở phần trên. Provisioning dùng CA MQTT chung đó để kết nối broker sau khi bootstrap.
6. Mở `Provisioning.ino`, biên dịch và nạp firmware. Sau lần bootstrap đầu, credential được lưu trong Preferences/NVS để dùng lại sau reboot.

Xem [luồng provisioning Access Token](../spec/devices/current/devices_access_token.md#provisioning-device-flow). Khi chuyển môi trường, cập nhật provisioning URL và CA trong simulator/firmware. Chỉ nhúng public CA certificate, không nhúng private key.

## File local và bảo mật

Các file thật sau đây bị ignore khỏi Git: `AccessToken/device_config.h`, `AccessToken/mqtt_ca_cert.h`, `Provisioning/provisioning_config.h` và `Provisioning/provisioning_ca_cert.h`. Chỉ các file `.example` chứa placeholder mới được đưa lên GitHub. Không commit Wi-Fi password, provisioning secret, Access Token hoặc certificate CA local; chỉ cài public CA certificate vào firmware, tuyệt đối không đưa CA private key vào sketch. Không tắt xác minh CA/hostname để xử lý lỗi kết nối.

## Ghi chú thêm cho Provisioning

Sketch dùng MQTT host do bootstrap trả về. Nếu tên DNS cục bộ như `mqtt.local` không phân giải được trên mạng của thiết bị, đặt `LB_MQTT_HOST_OVERRIDE` thành IP hoặc DNS broker mà thiết bị truy cập được và có trong SAN của certificate broker; xác minh TLS vẫn bật. Hai sketch dùng chung thư viện `ESP32/libraries/LiteBoardSetup`; `WiFi`, `WiFiClientSecure`, `HTTPClient`, `Preferences` và `cJSON` đi kèm ESP32 Arduino core.
