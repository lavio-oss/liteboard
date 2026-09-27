# Phase 4: Python Data Plane (`telemetry-service`) & Edge Clients (`simulator` / `ESP32`)

> Thuộc kế hoạch: [access_token_plan.md](../access_token_plan.md)  
> Tầng kiến trúc: **Data Plane & Edge Simulator (`Python FastAPI + gmqtt`, `Arduino ESP32`)**  
> Phụ thuộc: [Phase 2](./phase-2-laravel-control-plane.md), [Phase 3](./phase-3-mqtt-broker-mosquitto-go-auth.md)  
> Trạng thái: `Pending`

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
  - `PROJECT_ID`
  - `DEVICE_ID`
  - `DEVICE_ACCESS_TOKEN`
- Kết nối bằng `client.set_auth_credentials(DEVICE_ACCESS_TOKEN, DEVICE_ACCESS_TOKEN)`.
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
  constexpr uint16_t LB_PORT = 1883;
  const char *LB_PROJECT_ID = "019c0000-0000-7000-8000-000000000002";
  const char *LB_DEVICE_ID = "019c0000-0000-7000-8000-000000000100";
  const char *LB_ACCESS_TOKEN = "0bszem6ntw2bi0d28q1a";
  ```
- Kết nối MQTT:
  ```cpp
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

- [ ] `telemetry-service` đăng nhập thành công vào Mosquitto và subscribe `liteboard/+/devices/+/telemetry`, `liteboard/+/devices/+/heartbeat`.
- [ ] Khi chạy `simulator` (hoặc `mosquitto_pub`) với `ACCESS_TOKEN` hợp lệ, bản ghi `Device` tương ứng trong DB tự động chuyển `connection_state` từ `'offline'` sang `'online'` và `last_seen_at` được cập nhật.
