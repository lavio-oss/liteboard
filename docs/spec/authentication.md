# Đặc tả authentication

> Trạng thái đối chiếu: 2026-09-21. Phần “Đã triển khai” phản ánh code hiện có;
> phần “Chưa triển khai” là contract mục tiêu, không được hiểu là API đang hoạt động.

## 1. Phạm vi

LiteBoard hiện có authentication cho Laravel Control Plane bằng Laravel Sanctum.
Authentication của equipment/MQTT thuộc Data Plane và hiện chưa được triển khai.

Hai flow phải độc lập:

- User/browser/API client: session Sanctum stateful hoặc personal access token.
- Equipment/MQTT endpoint: credential riêng của equipment, chỉ dùng sau khi
  Control Plane đã cấp và quản lý credential.

Không dùng user token để xác thực equipment và không dùng equipment credential để
đăng nhập user.

## 2. User model và lưu trữ

User hiện tại:

- model `Modules\\Core\\Models\\User\\User` dùng `HasApiTokens`, `HasFactory`
  và `Notifiable`;
- password được cast bằng `hashed` và password/remember token bị ẩn;
- quan hệ nhiều-nhiều với `Tenant` qua `tenant_user` và pivot `role`;
- email là unique ở database;
- `email_verified_at` có thể null.

Schema được quản lý bằng migration:

- `users` và `sessions`;
- `personal_access_tokens`;
- `tenants` và `tenant_user`.
- `projects`, `factories` và `production_lines` thuộc module Masterdata, với
  tenant boundary được suy ra qua chuỗi parent.

Session local mặc định dùng database, thời hạn 120 phút. Cookie session local có
tên `liteboard-session`; production phải giữ cookie HttpOnly, HTTPS và cấu hình
domain/SameSite phù hợp. Việc migration đã được tạo không đồng nghĩa mọi môi
trường runtime đã chạy migration; triển khai phải chạy migration forward tương
ứng trước khi sử dụng các bảng.

## 3. Browser authentication đã triển khai

### 3.1 Endpoint

```text
POST /api/login
POST /api/logout
POST /api/logout-all
GET  /api/me
PATCH /api/me
PATCH /api/me/password

GET    /api/tenants
POST   /api/tenants
GET    /api/tenants/{tenant}
PATCH  /api/tenants/{tenant}
DELETE /api/tenants/{tenant}
```

`POST /api/login`:

1. Validate email/password tại `LoginRequest`.
2. Lowercase email tại request boundary.
3. Rate limit theo email và IP, tối đa 5 lần/phút.
4. Đăng nhập bằng guard `web` và regenerate session.
5. Trả `UserResource` gồm user và các tenant membership.

Login không tạo và không trả personal access token. Frontend dùng Sanctum CSRF
cookie, session cookie và `credentials: include`.

`POST /api/logout` hủy session hiện tại; nếu request đang dùng personal access
token thì revoke token hiện tại.

`POST /api/logout-all` revoke toàn bộ personal access token, xóa database sessions
của user và hủy session hiện tại.

`PATCH /api/me` chỉ cập nhật `name` và `email` của authenticated user. Role,
`user_id` và `tenant_id` bị cấm trong payload.

`PATCH /api/me/password` yêu cầu current password, password mới tối thiểu 8 ký tự,
confirmation và password mới phải khác password cũ.

Tenant management dùng cùng Sanctum session/token boundary. GET yêu cầu
`control-plane:read`; POST/PATCH/DELETE yêu cầu `control-plane:write` và còn bị
giới hạn bởi `TenantPolicy`: owner/admin được create/update, chỉ owner được
delete. Create tenant attach creator với role `owner` trong một transaction.

### 3.2 Middleware và frontend

- Laravel bật `statefulApi()` và dùng `auth:sanctum` cho API nghiệp vụ.
- Backend luôn trả thông báo bằng tiếng Anh; `Accept-Language` không thay đổi nội dung API.
- Nuxt có route middleware `auth` và `guest`.
- Nuxt server proxy chuyển tiếp CSRF, các endpoint login/logout/profile/password
  và toàn bộ API tenant-scoped dưới `/api/tenants/*`.
- Với request SSR, request layer và proxy chuyển tiếp `cookie`, `x-xsrf-token`,
  `origin` và `referer` tới Laravel để Sanctum nhận diện đúng stateful frontend
  request.
- Frontend lưu trạng thái user trong Nuxt state, không lưu bearer token trong
  `localStorage`.

Frontend đã có login, logout, profile và đổi password. OAuth Google/GitHub trên
UI chỉ là nút demo, chưa có provider backend.

### 3.3 Duy trì session khi reload

Nuxt state chỉ là cache trạng thái trong runtime hiện tại. Nguồn sự thật sau khi
reload vẫn là session cookie và session record ở Laravel:

```text
Browser cookie
→ Nuxt auth middleware
→ GET /api/me
→ Nuxt SSR proxy
→ Laravel statefulApi() / auth:sanctum
→ web session guard
```

Khi mở hoặc reload route yêu cầu đăng nhập, `auth` middleware gọi `GET /api/me`.
Nếu Laravel trả user, state được khôi phục và route tiếp tục mở. Nếu trả `401`,
frontend chuyển về trạng thái guest và điều hướng tới `/login`.

Proxy phải giữ các header stateful nói trên; nếu chỉ chuyển cookie mà thiếu
`origin`/`referer`, request SSR có thể bị Sanctum xem là non-stateful và trả
`401`, khiến người dùng bị đưa về màn hình login dù session cookie còn tồn tại.

Các API tenant-scoped, bao gồm Masterdata Project/Factory/Production Line, dùng
cùng session authentication và không nhận tenant hoặc parent id từ request body.

## 4. Personal access token đã triển khai

Personal access token là flow riêng, không phải output của login:

```text
GET    /api/tokens
POST   /api/tokens
DELETE /api/tokens/{token}
POST   /api/tokens/{token}/rotate
```

Token mới nhận:

```json
{
  "name": "CI integration",
  "abilities": ["control-plane:read"],
  "expires_in_days": 30
}
```

Chỉ plain token ở response tạo/rotate được trả về một lần. List/detail chỉ trả
metadata, không trả secret. Ability hợp lệ hiện tại là:

```text
control-plane:read
control-plane:write
```

Expiration mặc định là 30 ngày, tối đa 90 ngày; `expires_at` của từng token là
nguồn sự thật. Token ability không thay thế Policy nghiệp vụ.

## 5. Security boundary

User API hiện đi theo boundary:

```text
Browser cookie → Nuxt SSR proxy → stateful Sanctum/session
hoặc bearer token
→ auth:sanctum
→ ability check nếu endpoint yêu cầu
→ tenant membership/context nếu endpoint tenant-scoped
→ Policy
→ Controller/Action
```

Authentication log hiện chỉ ghi metadata cần thiết: user id, IP, outcome, request
id hoặc hash email khi login thất bại. Không ghi password, plain token, secret hay
credential.

## 6. Equipment/MQTT: hiện trạng

Chưa có các thành phần sau:

- bảng `equipments` và equipment credential hash;
- credential issuance/rotation/revocation;
- MQTT ACL theo tenant/project/equipment;
- topic ownership check;
- schema validation, telemetry storage, command hoặc heartbeat authentication.

Data Plane hiện chỉ là skeleton:

- FastAPI có `/health` và `/ready`;
- telemetry service subscribe `liteboard/+/+/telemetry` QoS 1;
- message handler chỉ log topic và payload size;
- simulator publish payload demo gồm `device_uid`, `occurred_at`, `temperature`
  và `rpm` mỗi 5 giây;
- Mosquitto local đang `allow_anonymous true`.

Vì vậy không được mô tả telemetry/MQTT local hiện tại là authentication hoặc
production-ready.

## 7. Kiểm thử và trạng thái

Đã có feature tests cho:

- unauthenticated `401`;
- login thành công/sai credential/rate limit;
- session logout và logout-all;
- token create/list/revoke/rotate/expiry;
- profile/password update;
- không để token/password xuất hiện ngoài boundary cần thiết.

Trạng thái lần kiểm tra:

- `composer test`: 42 tests, 159 assertions pass;
- `composer stan`: pass, 0 errors;
- `composer pint`: pass.
- Migration Masterdata đã có trong source; trạng thái migration của database
  runtime phụ thuộc môi trường triển khai và phải được kiểm tra riêng.

Frontend typecheck chưa được xác nhận trong lần đối chiếu này vì Corepack không
verify được chữ ký `pnpm@12.4.1` và `node_modules` local đang thiếu dependency
`citty`.

Chưa có test equipment authentication vì chức năng chưa tồn tại.
