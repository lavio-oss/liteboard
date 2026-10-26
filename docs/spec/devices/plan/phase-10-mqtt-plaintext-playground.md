# Phase 10: MQTT plaintext playground `1883`

> Trạng thái: `1883` do broker `mosquitto-plain` phục vụ, mỗi thiết bị tự bật/tắt bằng cờ `devices.allow_plaintext`. Còn biên dịch firmware, downlink qua bridge, đo tải auth và firewall production.
> Thuộc kế hoạch: [future_device_security_and_ingestion_plan.md](./future_device_security_and_ingestion_plan.md)
> Phụ thuộc: MQTT Access Token auth/ACL hiện có, [Phase 7](./phase-7-mqtt-tls-server-certificate.md) (TLS `8883`), [Phase 8](./phase-8-quotas-and-rate-limiting.md) (quota/rate limit).

## 1. Mục tiêu

Mở cổng `1883` không TLS làm playground để người dùng thử nhanh bằng `mosquitto_pub`, MQTTX hoặc ESP32 mà không cần CA, giống mô hình ThingsBoard. `8883` TLS vẫn là đường khuyến nghị cho Device thật. Hai cổng chạy song song; từng Device tự quyết định có dùng `1883` hay không.

Bất biến phải giữ:

- Access Token và ACL theo `project_id`/`device_id` giống `8883`; `allow_anonymous false`. Tách biệt tenant không đổi.
- Cờ `devices.allow_plaintext` thuộc về Device (tenant-owned, qua Project). Chỉ người có quyền cập nhật Device mới đổi được (cùng policy `update`); người ngoài tenant nhận 404.
- Telemetry-service, simulator và healthcheck vẫn kết nối qua TLS `8883`. Tài khoản nội bộ không đăng nhập được trên `1883`.
- Access Token gửi qua `1883` coi như đã lộ.
- Không có cờ hệ thống làm trần: muốn tắt hẳn `1883` thì đóng cổng ở hạ tầng hoặc bỏ service `mosquitto-plain`.

## 2. Thiết kế

Plugin `mosquitto-go-auth` giữ trạng thái toàn cục, nên một broker không thể có auth khác nhau cho hai listener (đã thử với `per_listener_settings true`: listener `8883` bị ghi đè cấu hình, tài khoản nội bộ mất quyền). Vì vậy:

- Broker chính `mosquitto` chỉ có TLS `8883`.
- Service `mosquitto-plain` (`infrastructure/mosquitto/mosquitto-plain.conf`) mở `1883`, chỉ dùng backend `postgres`. Query xác thực và ACL thêm điều kiện `devices.allow_plaintext`; cache Redis dùng db `1` tách khỏi broker chính.
- Bridge `plaintext-bridge` đẩy `telemetry`, `heartbeat`, `commands/ack` lên broker chính qua TLS và nhận `commands` về. Tài khoản này chỉ có quyền đúng các topic đó (`infrastructure/mosquitto/acls`).
- API: `mqtt.endpoints` liệt kê `{port, tls}` theo từng Device; endpoint plaintext (`1883`, từ config `device_provisioning.mqtt_plaintext_port`) chỉ có khi Device có `allow_plaintext`. Device resource trả `allow_plaintext`; `PATCH .../devices/{device}` nhận `allow_plaintext` (boolean).
- UI: modal Credentials có công tắc "Allow connection without TLS" (người có quyền quản lý); badge và firmware config theo endpoint hiệu lực của Device đó.
- Migration `2026_10_26_000002`: thêm cột (mặc định tắt), bật cho Device đang có, bỏ bảng `platform_settings` của bản trước.

## 3. Đã hoàn thành và đã kiểm tra

- [x] Test backend `DevicePlaintextTest` (endpoint theo cờ, đổi cờ cho đúng một Device, validation, người ngoài tenant bị 404) và cập nhật test credentials/provisioning.
- [x] Runtime local (2026-10-26): trên `1883` token hợp lệ của Device có cờ publish được, message tới broker chính qua bridge; đặt cờ tắt thì `1883` từ chối sau tối đa ~7 giây, bật lại thì chạy lại; token sai, ẩn danh, tài khoản nội bộ bị từ chối; topic của Device/project khác bị ACL chặn; `8883` không đổi.
- [x] Firmware ESP32 có `LB_MQTT_TLS` (chưa biên dịch), `max_packet_size 65536`, `max_connections 1000` trên `1883`, hướng dẫn `docs/setup/esp32.md`.

## 4. Việc còn lại

1. Biên dịch `AccessToken.ino` ở cả hai chế độ (`LB_MQTT_TLS 1` và `0`).
2. Kiểm tra trên `1883`: Device chưa duyệt, credential đã revoke, và subscribe `commands` qua bridge (downlink) chưa thử.
3. Khi tắt cờ, kết nối đang mở không bị ngắt: broker chỉ từ chối đăng nhập mới và chặn publish/subscribe theo ACL sau tối đa 5 giây.
4. `mosquitto-plain` chưa có healthcheck (image không có `nc`, và anonymous bị từ chối). Bridge mất kết nối thì thiết bị vẫn đăng nhập được nhưng message không tới broker chính.
5. Đo tải PostgreSQL khi bị dồn đăng nhập sai; cân nhắc giới hạn tốc độ theo IP ở firewall (làm chung với [Phase 8](./phase-8-quotas-and-rate-limiting.md)).
6. Production: mở firewall `1883` nếu muốn dùng playground; đặt `MQTT_BRIDGE_PASSWORD` riêng, không dùng mặc định.

## 5. Tiêu chí nghiệm thu

- [x] Device không bật `allow_plaintext` bị `1883` từ chối và không có endpoint plaintext trong API; bật lại thì khôi phục; `8883` không đổi.
- [ ] ESP32 chạy được cả hai chế độ chỉ bằng cách đổi `LB_PORT`/`LB_MQTT_TLS`.
- [ ] Downlink `commands` hoạt động qua bridge.
- [ ] Có số đo tải auth khi bị dồn đăng nhập sai.
- [x] Tài liệu setup nêu rõ: token từng dùng qua `1883` phải rotate trước khi dùng production.
