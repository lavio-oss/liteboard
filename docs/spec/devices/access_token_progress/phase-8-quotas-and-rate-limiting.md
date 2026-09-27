# Phase 8: Connection Quota, Message-Size Quota & Rate Limiting theo Thiết bị

> Thuộc kế hoạch tương lai: [future_device_security_and_ingestion_plan.md](../future_device_security_and_ingestion_plan.md)  
> Tầng kiến trúc: **Mosquitto/EMQX Broker + Python Data Plane (`telemetry-service`) + Redis**  
> Phụ thuộc: [Phase 4](./phase-4-telemetry-data-plane-and-edge.md)  
> Trạng thái: `Future Plan`

---

## 1. Mục tiêu của Phase 8

Bảo vệ toàn bộ hệ thống LiteBoard (Broker, Data Plane, Redis, PostgreSQL) trước hiện tượng **Noisy Neighbor** (một thiết bị lỗi/bị hack bắn hàng nghìn gói tin mỗi giây hoặc gói tin dung lượng lớn làm tê liệt các Tenant khác) thông qua 3 hàng rào định lượng:
1. **Message-size Quota**: Giới hạn dung lượng tối đa của mỗi gói tin MQTT.
2. **Connection Quota**: Giới hạn số kết nối đồng thời trên mỗi Device, Project và Tenant.
3. **Rate Limiting per Device**: Giới hạn tốc độ gửi bản tin (`messages/sec`) và băng thông (`bytes/sec`) cho từng thiết bị.

---

## 2. Thiết kế Kỹ thuật Chi tiết

### 2.1. Cấu hình Quota trong `DeviceProfile` (hoặc `Project`)

Thêm các thông số giới hạn vào bảng `device_profiles` (hoặc cấu hình mặc định trên bảng `projects`):
```text
device_profiles (các cột quota)
  max_payload_bytes         integer NOT NULL DEFAULT 4096   -- 4 KB mặc định (tối đa 64 KB)
  max_messages_per_minute   integer NOT NULL DEFAULT 60     -- 1 msg/sec trung bình
  burst_messages_limit      integer NOT NULL DEFAULT 10     -- Cho phép dồn tối đa 10 msg tức thời
  max_concurrent_sessions   integer NOT NULL DEFAULT 1      -- 1 kết nối TCP/MQTT trên mỗi Device
```

---

### 2.2. Message-size Quota (Giới hạn Kích thước Bản tin)

Áp dụng phòng thủ 2 lớp:
1. **Lớp 1 — Chặn cứng tại MQTT Broker (`mosquitto.conf`)**:
   ```conf
   # Từ chối ngay lập tức mọi gói tin MQTT vượt quá 64 KB
   max_packet_size 65536
   ```
   Nếu thiết bị gửi gói tin > 64 KB, Mosquitto ngắt kết nối ngay ở tầng TCP mà không tốn bộ nhớ truyền sang `telemetry-service`.
2. **Lớp 2 — Kiểm tra mềm theo `DeviceProfile` tại `telemetry-service`**:
   - Trước khi parse JSON (`json.loads`), `telemetry-service` kiểm tra `len(payload) > device_profile.max_payload_bytes`.
   - Nếu vượt ngưỡng (ví dụ thiết bị cảm biến nhiệt độ chỉ được cấu hình `4096` bytes nhưng gửi `30 KB`), `telemetry-service` **không parse JSON**, tăng bộ đếm metric `telemetry_rejected_total{reason="PAYLOAD_TOO_LARGE"}` và ghi nhận sự kiện từ chối.

---

### 2.3. Connection Quota (Giới hạn Số lượng Kết nối)

1. **Ở cấp độ toàn Broker (`mosquitto.conf`)**:
   ```conf
   max_connections 5000
   max_inflight_messages 20
   max_queued_messages 100
   ```
2. **Ở cấp độ từng Thiết bị & Project/Tenant**:
   - Mỗi `Device` chỉ được phép duy trì **1 phiên kết nối đồng thời** (khi kết hợp với **Bind `clientId` ↔ `device`** ở Phase 6, MQTT Broker tự động đảm bảo chỉ có đúng 1 kết nối cho mỗi `clientId`).
   - Giới hạn số lượng `active devices` được phép tạo và kích hoạt trên mỗi `Project` / `Tenant` (`projects.max_devices_quota`): kiểm tra ngay trong `StoreDeviceRequest` và View `v_mqtt_users`.

---

### 2.4. Rate Limiting per Device (Token Bucket trên Redis tại `telemetry-service`)

#### Thuật toán Token Bucket nguyên tử bằng Redis Lua Script
Khi `telemetry-service` nhận một bản tin từ `liteboard/{project_id}/devices/{device_id}/telemetry`:
- Gọi Lua Script trên Redis với khóa `rl:dev:{device_id}`:
  - Dung lượng xô (Bucket Capacity): `burst_messages_limit` (ví dụ `10` tokens).
  - Tốc độ nạp lại (Refill Rate): `max_messages_per_minute / 60.0` (ví dụ `1.0` token/giây).
- **Nếu còn token (`allowed = 1`)**:
  - Trừ 1 token và cho phép gói tin đi tiếp vào bước Validate & Lưu DB.
- **Nếu hết token (`allowed = 0` — Thiết bị đang spam)**:
  - **Drop ngay tại RAM** (không gọi xuống PostgreSQL để bảo vệ DB!).
  - Tăng bộ đếm `telemetry_rate_limited_total{project_id, device_id}` trong Redis/Prometheus.
  - Chỉ ghi tối đa 1 bản ghi cảnh báo `RATE_LIMIT_EXCEEDED` mỗi 60 giây cho thiết bị đó để tránh việc chính log lỗi làm sập Database.

---

## 3. Checklist Nghiệm thu Phase 8

- [ ] Gửi gói tin > 64 KB vào Mosquitto bị Broker từ chối ngay lập tức.
- [ ] Gửi gói tin > `max_payload_bytes` (ví dụ > 4 KB) bị `telemetry-service` chặn trước khi parse JSON với lý do `PAYLOAD_TOO_LARGE`.
- [ ] Khi một thiết bị bắn burst 100 bản tin/giây (vượt ngưỡng `1 msg/s`, burst `10`), chỉ có 10 bản tin đầu được chấp nhận, 90 bản tin sau bị chặn tại Redis Rate Limiter mà CPU/IO của PostgreSQL không bị tăng vọt.
- [ ] Các thiết bị khác cùng Project vẫn gửi nhận dữ liệu bình thường (không bị ảnh hưởng bởi thiết bị đang spam).
