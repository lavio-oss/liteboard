# Phase 4: Python Data Plane (`telemetry-service`) & Edge Clients (`simulator` / `ESP32`)

> Thuộc kế hoạch: [access_token_plan.md](../access_token_plan.md)  
> Tầng kiến trúc: **Data Plane & Edge Simulator (`Python FastAPI + gmqtt`, `Arduino ESP32`)**  
> Phụ thuộc: [Phase 2](./phase-2-laravel-control-plane.md), [Phase 3](./phase-3-mqtt-broker-mosquitto-go-auth.md)  
> Trạng thái: Luồng telemetry nền tảng `Completed`; tích hợp TLS cho Python clients và ESP32 đã cập nhật trong source; kiểm thử kết nối end-to-end qua broker còn chờ.

---

## 1. Mục tiêu của Phase 4

Đồng bộ hóa Python Data Plane (`telemetry-service`), bộ giả lập (`simulator`) và code mẫu vi điều khiển (`ESP32 Base.ino`) với cơ chế xác thực Access Token và cấu trúc topic chuẩn của LiteBoard:
- `liteboard/{project_id}/devices/{device_id}/telemetry`
- `liteboard/{project_id}/devices/{device_id}/heartbeat`

Đồng thời, khi thiết bị gửi gói tin hợp lệ đầu tiên, `telemetry-service` tự động cập nhật trạng thái thiết bị trong PostgreSQL (`connection_state = 'online'`, `last_seen_at = now()`), hoàn thiện luồng kích hoạt thiết bị giống ThingsBoard.

---

## 2. Chi tiết Thay đổi ở `telemetry-service/`

### 2.1. Kết nối Broker có xác thực & Subscribe đúng Topic Namespace
Trong [telemetry-service/app/main.py](../../../../telemetry-service/app/main.py):
- Đọc biến môi trường `MQTT_USERNAME` và `MQTT_PASSWORD` (tài khoản nội bộ `telemetry-service`) để gọi `client.set_auth_credentials(username, password)` trước khi `connect()`.
- Kết nối tới hostname broker trên cổng `8883` bằng TLS 1.2 trở lên; nạp CA từ `MQTT_TLS_CA_FILE` và bật xác minh hostname. Không dùng kết nối MQTT plaintext.
- Đổi topic subscribe từ `liteboard/+/+/telemetry` (cũ) sang chuẩn:
  - `liteboard/+/devices/+/telemetry` (QoS 1)
  - `liteboard/+/devices/+/heartbeat` (QoS 1)

### 2.2. Phân tích Topic, Kiểm tra Ownership & Cập nhật Trạng thái `online`
Khi nhận bản tin trên `liteboard/{project_id}/devices/{device_id}/{channel}`:
1. Tách `project_id`, `device_id`, `channel` (`telemetry` | `heartbeat`) từ topic.
2. Kiểm tra tính hợp lệ của UUID `project_id` và `device_id`.
3. Cập nhật bảng `devices` trong PostgreSQL (sử dụng `asyncpg` hoặc `psycopg`):
   ```sql
   UPDATE devices
   SET last_seen_at = NOW(),
       connection_state = 'online',
       updated_at = NOW()
   WHERE id = $1
     AND project_id = $2
     AND status = 'active'
   ```
   *(Lưu ý: Không bao giờ log Access Token ra stdout/logs).*

---

## 3. Chi tiết Thay đổi ở `simulator/` & `ESP32`

### 3.1. Python Simulator ([simulator/app/main.py](../../../../simulator/app/main.py))
- Nhận các biến môi trường:
  - `MQTT_HOST`, `MQTT_PORT`
  - `MQTT_TLS_CA_FILE` (CA certificate dùng để xác minh broker)
  - `PROJECT_ID`
  - `DEVICE_ID`
  - `DEVICE_ACCESS_TOKEN`
- Kết nối bằng `client.set_auth_credentials(DEVICE_ACCESS_TOKEN, DEVICE_ACCESS_TOKEN)`.
- Dùng TLS context từ `MQTT_TLS_CA_FILE`; xác minh CA và hostname broker, không có fallback plaintext.
- Publish định kỳ bản tin chuẩn envelope lên `liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/telemetry`:
  ```json
  {
    "message_id": "01JXYZ...",
    "occurred_at": "2026-09-27T00:00:00Z",
    "values": {
      "temperature": 26.4,
      "rpm": 1450
    }
  }
  ```

### 3.2. Code mẫu ESP32 ([simulator/Esp32/Base/Base.ino](../../../../simulator/Esp32/Base/Base.ino))
- Thay cấu hình `mqtt.thingsboard.cloud` và `v1/devices/me/telemetry` bằng cấu hình LiteBoard:
  ```cpp
  const char *LB_HOST = "192.168.1.100";
  constexpr uint16_t LB_PORT = 8883;
  const char *LB_PROJECT_ID = "019c0000-0000-7000-8000-000000000002";
  const char *LB_DEVICE_ID = "019c0000-0000-7000-8000-000000000100";
  const char *LB_ACCESS_TOKEN = "0bszem6ntw2bi0d28q1a";
  ```
- Kết nối MQTT:
  - Dùng `WiFiClientSecure` và CA certificate do script development tạo (header `mqtt_ca_cert.h`). Gọi `setCACert()` trước khi kết nối.
  - `LB_HOST` phải khớp DNS name hoặc IP có trong SAN của chứng chỉ broker.
  - Access Token được gửi trong MQTT CONNECT sau khi TLS được xác minh.
  ```cpp
  #include <WiFiClientSecure.h>
  #include "mqtt_ca_cert.h"
  WiFiClientSecure wifiClient;
  wifiClient.setCACert(LB_MQTT_CA_CERT);
  mqttClient.connect(clientId, LB_ACCESS_TOKEN, LB_ACCESS_TOKEN);
  ```
- Publish vào topic `liteboard/<LB_PROJECT_ID>/devices/<LB_DEVICE_ID>/telemetry`.

---

## 4. Danh sách File Cần Tạo / Sửa

1. **[MODIFY]** [telemetry-service/requirements.txt](../../../../telemetry-service/requirements.txt) (thêm driver kết nối PostgreSQL nhẹ như `asyncpg` hoặc `psycopg[binary]`).
2. **[MODIFY]** [telemetry-service/app/main.py](../../../../telemetry-service/app/main.py)
3. **[MODIFY]** [docker-compose.yml](../../../../docker-compose.yml) (truyền biến môi trường DB và MQTT credentials cho `telemetry-service` và `simulator`).
4. **[MODIFY]** [simulator/app/main.py](../../../../simulator/app/main.py)
5. **[MODIFY]** [simulator/Esp32/Base/Base.ino](../../../../simulator/Esp32/Base/Base.ino)

---

## 5. Checklist Nghiệm thu Phase 4

- [x] `telemetry-service` đăng nhập thành công vào Mosquitto và subscribe `liteboard/+/devices/+/telemetry`, `liteboard/+/devices/+/heartbeat`.
- [x] Python Data Plane và simulator dùng TLS 1.2+, xác minh CA/hostname, không fallback plaintext; bộ test TLS đạt 3/3 cho mỗi service.
- [x] Mẫu ESP32 chuyển sang `WiFiClientSecure`, CA broker và cổng `8883` (chưa xác nhận biên dịch hoặc chạy trên board thật).
- [ ] End-to-end: Data Plane, simulator, healthcheck và ESP32 kết nối broker đang chạy qua TLS trên `8883`, xác minh CA/hostname và giữ nguyên Access Token/ACL.
- [x] Khi chạy `simulator` (hoặc `mosquitto_pub`) với `ACCESS_TOKEN` hợp lệ, bản ghi `Device` tương ứng trong DB tự động chuyển `connection_state` từ `'offline'` sang `'online'` và `last_seen_at` được cập nhật.

> Ghi chú tiến độ: Docker Compose xác nhận cấu hình hợp lệ, nhưng Docker Engine không truy cập được qua `npipe://./pipe/docker_engine`; vì vậy các mục runtime/end-to-end chưa được đánh dấu hoàn tất.
