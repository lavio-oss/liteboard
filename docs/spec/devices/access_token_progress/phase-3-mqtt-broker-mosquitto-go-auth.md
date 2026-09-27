# Phase 3: MQTT Broker Infrastructure — `mosquitto-go-auth` (`infrastructure/mosquitto/`)

> Thuộc kế hoạch: [access_token_plan.md](../access_token_plan.md)  
> Tầng kiến trúc: **MQTT Broker Infrastructure (`Mosquitto + mosquitto-go-auth + Redis Cache`)**  
> Phụ thuộc: [Phase 1](./phase-1-database-and-sql-views.md)  
> Trạng thái: `Completed`

---

## 1. Mục tiêu của Phase 3

Chuyển đổi Mosquitto Broker từ chế độ mở (`allow_anonymous true`) sang chế độ xác thực và phân quyền chặt chẽ theo từng thiết bị bằng plugin **`iegomez/mosquitto-go-auth`** kết nối trực tiếp vào PostgreSQL Views (`v_mqtt_users`, `v_mqtt_acls`) và sử dụng Redis làm bộ nhớ đệm (cache).

---

## 2. Cơ chế Xác thực Kép (`files` + `postgres`)

Plugin `mosquitto-go-auth` cho phép chạy song song hai backend theo thứ tự ưu tiên: `auth_opt_backends files, postgres`.

1. **Backend `files` (Tài khoản hệ thống nội bộ)**:
   - Dành cho `telemetry-service` (Python Data Plane) và `healthcheck` container.
   - `telemetry-service`: được cấp quyền đọc/ghi trên `liteboard/#`.
   - `healthcheck`: chỉ được cấp quyền publish vào topic `healthcheck`.
2. **Backend `postgres` (Xác thực Thiết bị bằng Access Token)**:
   - Khi thiết bị kết nối với `username = <ACCESS_TOKEN>` và `password = <ACCESS_TOKEN>`:
     - **Bước 1 — `auth_opt_pg_userquery`**:
       ```sql
       SELECT mqtt_password_hash FROM v_mqtt_users WHERE token_hash = encode(sha256(convert_to($1, 'UTF8')), 'hex') LIMIT 1
       ```
       Plugin lấy `mqtt_password_hash` (`$2a$...`) và tự động so khớp `bcrypt` với `password` do thiết bị gửi lên.
     - **Bước 2 — `auth_opt_pg_aclquery`**:
       ```sql
       SELECT topic FROM v_mqtt_acls WHERE token_hash = encode(sha256(convert_to($1, 'UTF8')), 'hex') AND rw = $2
       ```
       Đảm bảo thiết bị chỉ được publish/subscribe đúng topic chứa `{project_id}` và `{device_id}` của nó.
3. **Redis Caching**:
   - Bật cache kết quả Auth và ACL trong `5 giây` trên Redis (`auth_opt_auth_cache_seconds 5`, `auth_opt_acl_cache_seconds 5`) để tránh truy vấn PostgreSQL trên từng bản tin telemetry tần suất cao, nhưng vẫn đảm bảo thu hồi quyền gần như tức thời (tối đa 5s) khi Admin bấm **Revoke** hoặc **Rotate** token.

---

## 3. Danh sách File Cần Tạo / Sửa

1. **[MODIFY]** [infrastructure/mosquitto/mosquitto.conf](../../../../infrastructure/mosquitto/mosquitto.conf)
   - Thay `allow_anonymous true` bằng `allow_anonymous false`.
   - Cấu hình `auth_plugin /mosquitto/go-auth.so`, backend `files, postgres` và Redis cache sử dụng biến runtime (`__MQTT_PG_HOST__`, `__MQTT_PG_PASSWORD__`, ...) để không lưu thông tin nhạy cảm trên Git.
2. **[NEW]** [infrastructure/mosquitto/acls](../../../../infrastructure/mosquitto/acls)
   - Định nghĩa quyền cho `user telemetry-service` (`topic readwrite liteboard/#`, `topic subscribe liteboard/#`) và `user healthcheck` (`topic write healthcheck`).
3. **[MODIFY]** [docker-compose.yml](../../../../docker-compose.yml) & [.env.example](../../../../.env.example)
   - Đổi `image` của service `mosquitto` sang `iegomez/mosquitto-go-auth:latest`.
   - Tự động sinh `/tmp/mosquitto-passwords` lúc khởi động container bằng `/mosquitto/pw -h bcrypt -c 10` từ các biến môi trường `MQTT_INTERNAL_PASSWORD` và `MQTT_HEALTHCHECK_PASSWORD` trong `.env` (không lưu file `passwords` tĩnh trên Git).
   - Cập nhật `healthcheck` của `mosquitto` sử dụng `$MQTT_HEALTHCHECK_USERNAME` / `$MQTT_HEALTHCHECK_PASSWORD`.
   - Thêm `depends_on` tới `postgres` và `redis`.

---

## 4. Kịch bản Kiểm thử Bảo mật Broker (Failure Exercises)

| Kịch bản | Hành động | Kết quả kỳ vọng |
|---|---|---|
| **1. Kết nối ẩn danh** | `mosquitto_pub -h localhost -t test -m "hi"` (không truyền `-u`, `-P`) | Bị từ chối kết nối (`Connection Refused: not authorized`) |
| **2. Sai Access Token** | Truyền `-u "wrong_token" -P "wrong_token"` | Bị từ chối kết nối |
| **3. Đúng Access Token, đúng Topic** | Truyền `-u "$TOKEN_A" -P "$TOKEN_A"` publish vào `liteboard/{proj_A}/devices/{dev_A}/telemetry` | Thành công (`QoS 1 PUBACK`) |
| **4. Giả mạo Topic thiết bị khác** | Truyền `-u "$TOKEN_A" -P "$TOKEN_A"` nhưng publish vào `liteboard/{proj_A}/devices/{dev_B}/telemetry` | Bị chặn bởi ACL (Broker không chuyển tiếp gói tin) |
| **5. Thu hồi / Đổi Token (Revoke / Rotate)** | Đổi trạng thái credential sang `revoked` trên UI rồi gửi lại gói tin sau 5s | Bị từ chối kết nối / publish |

---

## 5. Checklist Nghiệm thu Phase 3

- [x] Container `mosquitto` khởi động `healthy` cùng `postgres` và `redis`.
- [x] Vượt qua toàn bộ 5 kịch bản kiểm thử bảo mật Broker ở Mục 4.
