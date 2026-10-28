# Backend ↔ Telemetry service: kết nối và xác thực — trạng thái hiện tại

> Đối chiếu code ngày 2026-10-18 (Laravel `backend/`, Python `telemetry-service/`, `docker-compose.yml`). Mô tả implementation hiện tại, không xác nhận cấu hình môi trường pilot hay production. Phần "Điểm yếu" là đánh giá từ code, chưa phải kết quả pentest.

## 1. Hai bên kết nối bằng gì

Laravel (control plane) và telemetry service (data plane) liên kết qua các cơ chế sau. Chỉ có một lời gọi API: service gọi Laravel để tạo sub-device của gateway.

| Cơ chế | Chiều | Dùng để |
|---|---|---|
| Cùng database PostgreSQL | Service đọc/ghi trực tiếp bảng của Laravel; Laravel đọc lại để trả REST | Resolve Device/Project/Tenant, ghi `telemetry_samples`, `telemetry_ingest_outcomes`, `telemetry_latest_values`, cập nhật presence |
| Khóa bí mật dùng chung `TELEMETRY_WS_SECRET` | Laravel ký, service kiểm tra | Cấp và xác thực vé WebSocket (telemetry stream, device presence) |
| `POST /api/internal/gateways/{gateway}/sub-devices` với header `X-Internal-Token` = `TELEMETRY_INTERNAL_SECRET` (≥ 32 ký tự) | Service gọi Laravel (`LARAVEL_INTERNAL_URL`) | Tạo sub-device khi gateway gửi code mới; Laravel giữ quota, Default profile và quy tắc tenant. Lỗi mạng hoặc 5xx không ack message để broker gửi lại; 409 ghi unresolved |

Hệ quả: đồng bộ giữa hai bên dựa trên cùng schema và cùng quy ước, không có hợp đồng API riêng. Đổi cấu trúc bảng ở Laravel có thể làm service hỏng mà không có kiểm tra tương thích nào.

## 2. Luồng xác thực

### 2.1 Thiết bị → broker → service

- Thiết bị đăng nhập MQTT bằng access token; broker (Mosquitto + go-auth, backend Postgres) giới hạn publish vào topic của chính thiết bị.
- Service đăng nhập broker bằng tài khoản nội bộ riêng (`MQTT_USERNAME`, mặc định `telemetry-service`) qua TLS (CA qua `MQTT_TLS_CA_FILE`).
- `project_id` và `device_id` trong topic chỉ dùng để định tuyến. Service xác nhận cặp (device, project) và trạng thái `active`/`approved` của Device, Project, Tenant trong PostgreSQL (`SELECT_DEVICE_CONTEXT_SQL`). Không lấy `tenant_id` từ firmware hay payload.

### 2.2 Trình duyệt → WebSocket của service

1. Frontend gọi Laravel: `POST .../telemetry/stream-ticket` (hoặc `.../presence-stream-ticket`). Route này đi qua Sanctum, tenant context, quyền đọc và policy của Device, nên chỉ người có quyền đọc thiết bị mới xin được vé.
2. Laravel (`TelemetryStreamTicketService`) ký vé: `base64url(payload).base64url(HMAC-SHA256(payload, TELEMETRY_WS_SECRET))`. Payload gồm `sub` (user), `project_id`, `device_id`, `exp`. Thời hạn 60 giây.
3. Trình duyệt mở `wss://.../ws/telemetry?ticket=...` (hoặc `/ws/device-presence`).
4. Service (`realtime/ticket.py`) kiểm tra chữ ký bằng `hmac.compare_digest`, kiểm tra hết hạn, kiểm tra `project_id`/`device_id` là UUID hợp lệ. Sai một điều kiện: đóng socket mã `4401`.
5. Trình duyệt gửi `{"type":"renew","ticket":"..."}` trước khi hết hạn. Vé mới phải cùng user, project, device; nếu không hoặc hết hạn thì service đóng socket mã `4403`. Quyền bị thu hồi có hiệu lực trong tối đa 60 giây.
6. Service không có khóa (độ dài dưới 32 ký tự) thì từ chối mở WebSocket (`1011`) nhưng vẫn ingest MQTT.

Mỗi socket chỉ nhận event của một cặp (project, device) đã ghi trong vé. Hàng đợi đầy thì socket bị ngắt (`4008`), client phục hồi khoảng trống qua REST.

### 2.3 REST telemetry

REST nằm ở Laravel, không nằm ở service: Sanctum, `tenant.context`, read ability, scoped route binding và Device policy. API unresolved trung tâm chỉ dành cho platform admin.

## 3. Điểm yếu đã thấy

1. **Cùng user DB cho Laravel và service.** Service nối bằng `POSTGRES_USER`/`POSTGRES_PASSWORD` của stack, nên có quyền đọc ghi mọi bảng. Không có ranh giới tenant ở tầng DB; lỗi ở service có thể ghi sang bảng không liên quan.
2. **Cổng HTTP `8100` được map ra ngoài** (`TELEMETRY_PORT`). `/health` và `/ready` không có auth (chỉ trả trạng thái). WebSocket được bảo vệ bằng vé.
3. **Giá trị mặc định yếu trong `docker-compose.yml`:** `TELEMETRY_WS_SECRET` mặc định `local-dev-telemetry-ws-secret-change-me`, mật khẩu MQTT nội bộ mặc định `telemetry-service-secret`, DB mặc định `liteboard/liteboard`. Chấp nhận được ở local; chưa kiểm tra giá trị thật ở môi trường khác.
4. **Không kiểm tra `Origin` trên WebSocket.** Bảo vệ hoàn toàn dựa vào vé.
5. **Không có xác thực giữa hai bên ở tầng mạng hay lời gọi.** Ai ghi được DB hoặc biết `TELEMETRY_WS_SECRET` thì tự dựng được vé hợp lệ.
6. **Endpoint nội bộ tạo sub-device nằm chung `/api`.** Request đi qua web proxy cũng tới được endpoint này, nên chỉ secret `TELEMETRY_INTERNAL_SECRET` bảo vệ nó. Nên chặn `/api/internal/*` ở reverse proxy.
7. **Khóa dùng chung không có cơ chế xoay.** Đổi khóa phải đổi đồng thời ở Laravel và service, các vé đang sống sẽ không hợp lệ.

## 4. Hướng làm chặt hơn (chưa thực hiện)

- User DB riêng cho service: chỉ ghi các bảng telemetry/presence, chỉ đọc `devices`, `projects`, `tenants`, `device_profile_versions`.
- Từ chối khởi động (hoặc từ chối WebSocket) khi còn dùng giá trị mặc định của `TELEMETRY_WS_SECRET`; không map `8100` ra ngoài, chỉ đi qua reverse proxy.
- Kiểm tra `Origin` cho `/ws/*`.
- Hỗ trợ hai khóa cùng lúc (khóa cũ và mới) để xoay khóa không làm gián đoạn.

## 5. File liên quan

| Việc | File |
|---|---|
| Cấp vé | `backend/modules/Admin/Services/Telemetry/TelemetryStreamTicketService.php`, `DevicePresenceStreamTicketService.php` |
| Route và policy cấp vé | `backend/modules/Admin/Routes/telemetry.php`, `Actions/Telemetry/Http/Issue*TicketAction.php` |
| Cấu hình khóa (Laravel) | `backend/config/services.php` (`telemetry.ws_secret`) |
| Kiểm tra vé | `telemetry-service/app/realtime/ticket.py`, `presence_ticket.py` |
| WebSocket | `telemetry-service/app/realtime/stream.py`, `presence_stream.py` |
| Khởi tạo, DB, khóa | `telemetry-service/app/main.py` |
| Resolve scope khi ingest | `telemetry-service/app/storage/queries.py`, `telemetry_ingest.py` |
| Biến môi trường | `docker-compose.yml` (service `telemetry-service`, `mosquitto`) |
