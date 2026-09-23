# Đặc tả access control

> Trạng thái đối chiếu: 2026-09-23. Các mục được đánh dấu “đã triển khai” chỉ
> mô tả behavior đã kiểm tra trong repository hiện tại.

## 1. Nguyên tắc

- Sanctum xác thực user; Laravel Policy quyết định quyền trên resource.
- Từ chối mặc định nếu user không thuộc tenant hoặc Policy không cho phép.
- Quyền tenant nằm trong membership `tenant_user`, không nằm trong cờ global như
  `users.is_admin`.
- Không tin `tenant_id` do frontend gửi trong request body.
- Query list phải giới hạn theo membership/tenant trước khi phân trang.
- Không có custom role runtime, dynamic permission catalog, inheritance hoặc ACL
  riêng trong phase hiện tại.

## 2. Phân loại dữ liệu hiện tại

### Central data đã có

- `users`;
- `tenants`;
- `tenant_user`;
- `personal_access_tokens`;
- `sessions`, cache và jobs theo cấu hình Laravel.

### Tenant-owned data đã triển khai một phần

Module `Masterdata` đã triển khai backend foundation cho dữ liệu tenant-owned:

- `projects` thuộc `tenants` và là workspace;
- `assets` thuộc `projects`;
- `entity_relations` thuộc `projects`, hiện chỉ có `asset contains asset`.

Các resource còn lại vẫn mới là contract trong `docs/plan.md`, chưa có model,
migration, route hoặc Policy: Device/MQTT, telemetry schemas, rules, alerts,
commands và webhooks. Vì vậy tenant isolation chưa được claim là hoàn thiện cho
toàn bộ hệ thống IoT.

## 3. Tenant và membership

Tenant hiện có các field:

```text
id
parent_id nullable
name
code unique, tối đa 64 ký tự
status, mặc định active
created_at
updated_at
```

Model có quan hệ `parent`, `children` và `users`. `parent_id` mới chỉ là nền tảng
model/schema; chưa có API quản lý tenant hierarchy.

Bảng `tenant_user` có:

```text
tenant_id
user_id
role: owner | admin | member
created_at
updated_at
```

Cặp `tenant_id + user_id` unique. Một user có thể thuộc nhiều tenant với role
khác nhau.

| Role | Quyền hiện được định nghĩa |
|---|---|
| owner | xem tenant, update tenant, delete tenant, quản lý membership theo contract |
| admin | xem và update tenant; có thể tạo tenant theo Policy hiện tại |
| member | xem tenant; không update/delete tenant |

Lưu ý: membership management API chưa tồn tại, nên quyền “quản lý membership”
mới là contract role, chưa phải behavior chạy được.

## 4. Policy đã triển khai

`TenantPolicy` đã được đăng ký trong `CoreServiceProvider`:

```text
viewAny:
  user phải có ít nhất một tenant membership

view:
  tenant phải có user là member

create:
  user phải là owner hoặc admin của ít nhất một tenant hiện có

update:
  user phải là owner hoặc admin của tenant mục tiêu

delete:
  user phải là owner của tenant mục tiêu
```

Ngoài `TenantPolicy`, `MasterdataServiceProvider` đã đăng ký
`ProjectPolicy` và `AssetPolicy`. Asset policy xác định tenant qua Project;
member được đọc/list, còn owner/admin hoặc project role phù hợp mới được
create/update/delete. Chưa có Device/MQTT, `AlertPolicy` hoặc `CommandPolicy`
trong code.

## 5. API authorization hiện tại

Các route đã triển khai:

```text
GET /api/tenants
GET /api/tenants/{tenant}
POST /api/tenants
PATCH /api/tenants/{tenant}
DELETE /api/tenants/{tenant}

GET|POST /api/tenants/{tenant}/projects
GET|PATCH|DELETE /api/tenants/{tenant}/projects/{project}
GET|POST /api/tenants/{tenant}/projects/{project}/assets
GET|PATCH|DELETE /api/tenants/{tenant}/projects/{project}/assets/{asset}
```

Các route đọc tenant yêu cầu `auth:sanctum` và `control-plane:read`; route
Masterdata cũng dùng Sanctum, với ability read cho GET và write cho POST/PATCH/
DELETE.

`GET /api/tenants` query qua `$user->tenants()` và chỉ trả tenant của user.

`GET /api/tenants/{tenant}` dùng:

```text
auth:sanctum
→ abilities:control-plane:read
→ tenant.context
→ TenantPolicy::view
→ TenantController
```

Tenant context middleware:

- lấy tenant từ route binding;
- kiểm tra authenticated user là member;
- trả `404` nếu tenant không tồn tại hoặc user không thuộc tenant;
- lưu `tenant_id` vào Laravel Context.

Header `X-Tenant-ID` không có vai trò quyết định context và không thể chuyển user
sang tenant khác.

Tenant CRUD đã có với ability `control-plane:read/write` và Policy tương ứng.
Membership management và các resource Device/MQTT chưa tồn tại. Các
route Masterdata dùng `auth:sanctum`, ability read/write,
`tenant.context`, scoped binding và trả `404` khi parent-child không cùng cây
hoặc khác tenant. Request body không nhận `tenant_id` hoặc `project_id`.

## 6. Route binding và request boundary

Tenant-scoped route phải dùng route model binding và membership validation ở server.
Resource thuộc tenant khác phải trả `404`, không trả chi tiết để tránh lộ sự tồn
tại.

Request create/update không nhận `tenant_id` từ client. Parent và tenant phải được
gắn tại persistence boundary từ route/context đã xác thực.

Các endpoint nhận input phải dùng `FormRequest`, typed accessors, `validated()` và
Policy authorization. Không dùng pipe-delimited validation rule hoặc raw
`Request::input()` trong domain flow.

## 7. Audit và transaction

Chưa có authorization audit table hoặc membership audit event implementation.

Khi bắt đầu tenant/membership mutation:

- thay đổi nhiều bản ghi phải atomic trong transaction;
- log security event với actor id, tenant id, target id và outcome;
- không log password, token, secret hoặc credential;
- migration chỉ forward-only, không thêm `down()`.

## 8. Kiểm thử đã có

`TenantAuthorizationTest` hiện kiểm tra:

- list chỉ trả tenant của authenticated user;
- member đọc được tenant mình thuộc;
- user ngoài tenant nhận `404`;
- fake `X-Tenant-ID` không đổi được context;
- role matrix owner/admin/member của `TenantPolicy`;
- owner tạo tenant và được attach role owner;
- admin update tenant;
- owner delete tenant;
- duplicate tenant code bị từ chối.

`MasterdataAuthorizationTest` kiểm tra thêm nested binding, role matrix cho
Project/Asset, cross-tenant isolation, parent mismatch, self-parenting/cycle,
duplicate code, search/pagination, relation migration và cascade
delete/key-update.

Lần kiểm tra hiện tại: backend pass 62 tests và 254 assertions, trong đó các
test authorization, hierarchy relation và cascade của Masterdata pass.

## 9. Phần còn lại

Ưu tiên tiếp theo sau Tenant CRUD/UI là user và membership management:

1. Tenant selector thật trên Nuxt thay cho team mock.
2. Tenant settings/overview UI dùng `/api/tenants`.
3. Member list, invitation/account creation, role update và
   remove membership.

Chưa triển khai và không được coi là đã có:

- archive policy cho tenant;
- tenant hierarchy UI/API;
- custom roles/permissions;
- authorization cache;
- equipment isolation runtime;
- membership management UI.
