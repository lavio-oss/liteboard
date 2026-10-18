# Đặc tả tenant management

> Đây là đặc tả riêng cho tenant management, tách khỏi user authentication và
> authorization nền tảng.

## 1. Trạng thái hiện tại

Tenant là dữ liệu central dùng để phân vùng quyền và điều phối dữ liệu IoT. Code
hiện đã có:

- migration `tenants`;
- migration `tenant_user`;
- model `Tenant` và factory;
- `TenantRole` gồm `owner`, `admin`, `member`;
- `TenantContextMiddleware`;
- `TenantPolicy`;
- `TenantResource`;
- endpoint list/detail/create/update/delete tenant;
- seed tenant `demo` với user `Administrator <admin@gmail.com>` là platform admin;
- feature tests cho membership isolation, role matrix và tenant CRUD.

Tenant CRUD và tenant management UI đã có ở mức V1. UI hiển thị các tenant user
được phép thấy, thêm/sửa qua modal và xóa tenant qua confirmation modal. API chưa
có membership management nên role của user vẫn chỉ được quản lý ở backend/pivot.

Tenant hierarchy `parent_id` vẫn là quan hệ tenant-to-tenant riêng và chưa có
API hierarchy. Dữ liệu nghiệp vụ bên dưới tenant đã có backend module riêng
`Masterdata` với cây:

```text
Tenant
└── Project
    └── Asset graph (`contains`)
        ├── Site
        ├── Factory
        ├── Area
        ├── Production Line
        ├── Station
        └── Equipment
```

Module này không di chuyển `Tenant` khỏi `Core`. Equipment là một `AssetType`,
không có bảng riêng; Device/MQTT là phase sau.

## 2. Data contract

Tenant resource hiện trả:

```json
{
  "id": "019c0000-0000-7000-8000-000000000001",
  "name": "Demo Tenant",
  "code": "demo",
  "status": "active",
  "role": "owner"
}
```

`role` không phải thuộc tính của tenant; nó được đọc từ `tenant_user` pivot của
authenticated user.

Schema hiện tại:

```text
tenants.id                 uuid primary key, returned as a UUID string
tenants.parent_id          uuid nullable, self-reference, nullOnDelete
tenants.name               varchar(255)
tenants.code               varchar(64), unique
tenants.status             varchar(32), default active

tenant_user.tenant_id      uuid foreign key, cascade update/delete
tenant_user.user_id        uuid foreign key to users.id, cascade update/delete
tenant_user.role           varchar(32), default member
tenant_user.(tenant_id,user_id) unique
```

`parent_id` đã có trong schema/model nhưng chưa có contract cho tenant hierarchy;
không được tự suy ra rằng tenant con đã được hỗ trợ ở UI/API. Điều này không
ảnh hưởng đến cây Masterdata bên dưới tenant.

### 2.1. Masterdata hierarchy đã triển khai

Các bảng tenant-owned và khóa ngoại là:

```text
projects.tenant_id               NOT NULL, ON UPDATE CASCADE, ON DELETE CASCADE
assets.project_id                NOT NULL, ON UPDATE CASCADE, ON DELETE CASCADE
entity_relations.project_id      NOT NULL, ON UPDATE CASCADE, ON DELETE CASCADE
```

Asset có `unique(project_id, asset_type, code)`. `entity_relations` là nguồn sự
thật cho quan hệ `asset contains asset` và giới hạn mỗi asset tối đa một parent.
`parent_asset_id` chỉ là projection trong API/UI.

CRUD API dùng nested scoped routes:

```text
/api/tenants/{tenant}/projects
/api/tenants/{tenant}/projects/{project}/assets
/api/tenants/{tenant}/projects/{project}/assets/{asset}
```

Member được list/read. Owner/admin được create/update/delete. Parent được gửi
qua `parent_asset_id` nhưng được kiểm tra cùng Project, chống self-parenting và
cycle. Xóa parent giữ lại child và xóa các relation liên quan để child thành
root.

## 3. API hiện tại

```text
GET /api/tenants
GET /api/tenants/{tenant}
POST /api/tenants
PATCH /api/tenants/{tenant}
DELETE /api/tenants/{tenant}
```

GET yêu cầu authenticated Sanctum user và `control-plane:read`. POST, PATCH và
DELETE yêu cầu `control-plane:write`.

`GET /api/tenants` chỉ query qua `$user->tenants()` và sort theo tên.

`GET /api/tenants/{tenant}` chỉ thành công khi user là member của tenant. Tenant
không thuộc user trả `404`.

`POST /api/tenants` tạo tenant và attach user hiện tại với role `owner` trong một
transaction. `PATCH /api/tenants/{tenant}` chỉ nhận `name`, `code` và `status`;
`code` phải unique. `DELETE /api/tenants/{tenant}` chỉ dành cho owner và là hard
delete; Project và Asset bên dưới tenant được DB cascade xóa, cùng các
`entity_relations` theo `project_id`.

## 4. Policy hiện tại

| Action | Quyền |
|---|---|
| viewAny | user có ít nhất một membership |
| view | member của tenant mục tiêu |
| create | owner/admin của một tenant hiện có |
| update | owner/admin của tenant mục tiêu |
| delete | owner của tenant mục tiêu |

Policy create không nhận tenant mục tiêu vì tenant mới chưa có. Flow tạo tenant
đã attach membership owner trong cùng một transaction.

## 5. Frontend hiện tại

Đã có:

- Nuxt UI Dashboard SSR;
- auth/guest route middleware;
- `AuthUser` chứa danh sách tenant membership;
- guest middleware điều hướng tenant đầu tiên về `/tenants/{id}`;
- `TeamsMenu.vue` làm khung tenant selector;
- `/tenants` list tenant dùng `GET /api/tenants`;
- nút Add ở header, edit/delete actions và modal form/confirmation;
- role-based visibility: owner/admin được add/edit, owner được delete.

Chưa có:

- tenant selector dùng dữ liệu API thật;
- `/tenants/[tenant]/` page;
- `/settings/tenants` page;
- membership management UI/API;
- tenant hierarchy UI/API.

`TeamsMenu.vue` hiện còn dùng dữ liệu demo Nuxt/NuxtHub/NuxtLabs. Đây không phải
tenant data thật.

Nuxt server proxy đã chuyển tiếp route tenant cùng CSRF và auth/profile endpoint.
`TeamsMenu.vue` vẫn còn dữ liệu demo; tenant list `/tenants` mới là flow dùng API
thật.

## 6. Contract V1 đã triển khai

Tenant management V1 đã triển khai các endpoint:

```text
POST  /api/tenants
PATCH /api/tenants/{tenant}
DELETE /api/tenants/{tenant}
```

Create request tối thiểu:

```json
{
  "name": "Factory A",
  "code": "factory-a"
}
```

Update chỉ nhận field tenant được cho phép; không nhận `tenant_id`, role hoặc user
id. `code` phải unique và validation phải nằm trong FormRequest boundary.

V1 dùng hard-delete tenant theo cascade Masterdata đã chốt. Archive/deactivate,
membership management và tenant hierarchy API chỉ được thêm sau khi chốt
behavior với Projects, Equipment và telemetry retention.

## 7. Acceptance criteria cho tenant management

- User chỉ thấy tenant mình thuộc.
- Cross-tenant URL trả `404`.
- Owner/admin có thể sửa tenant theo Policy.
- Owner có thể xóa tenant theo Policy và cascade Masterdata đúng.
- Member không thể sửa tenant.
- Code trùng trả lỗi validation và không đổi dữ liệu cũ.
- Create tenant không để lại tenant thiếu owner nếu membership attach thất bại.
- Tenant list UI không sử dụng dữ liệu mock và dùng `GET /api/tenants`.
- Tenant hiện tại được biểu diễn rõ bằng URL/context server-side.
- API response và Zod schema frontend dùng cùng field names, không mapping alias.
- Backend feature tests và PHPStan pass trước khi format: 62 tests, 254 assertions.
