# Đặc tả Tenant, Project và Asset Master Data

> Phiên bản: 2026-09-23  
> Phạm vi: Control Plane và Masterdata  
> Trạng thái: contract hiện tại của repository

## 1. Mục tiêu và phạm vi

Đặc tả này định nghĩa ranh giới dữ liệu, quyền truy cập và API cho chuỗi:

```text
Tenant
└── Project workspace
    └── Asset graph
        ├── Site
        ├── Factory
        ├── Area
        ├── Production Line
        ├── Station
        └── Equipment
```

`Equipment` là một giá trị của `AssetType`, không phải bảng riêng. Quan hệ
giữa các Asset chỉ dùng `contains` và được lưu trong `entity_relations`.

Các nội dung sau chưa thuộc đặc tả này: Device, MQTT identity, credential,
firmware, last-seen, telemetry, command, rule và alert.

## 2. Ranh giới dữ liệu

### 2.1 Tenant

`Tenant` là boundary bảo mật cao nhất. Tenant chứa membership người dùng và
sở hữu các Project bên dưới.

Tenant là dữ liệu trung tâm của Control Plane, gồm:

```text
tenants
  id
  parent_id nullable
  name
  code unique
  status: active | inactive
  created_at
  updated_at

tenant_user
  tenant_id
  user_id
  role: owner | admin | member
```

`parent_id` là quan hệ Tenant-to-Tenant riêng, không được dùng để biểu diễn
hierarchy của Asset.

### 2.2 Project

`Project` là workspace nghiệp vụ thuộc đúng một Tenant. Project đồng thời là
boundary phân quyền tài nguyên Masterdata bên dưới Tenant, nhưng không thay
thế Tenant security boundary.

```text
projects
  id
  tenant_id NOT NULL
  name
  description nullable
  status: active | inactive
  created_at
  updated_at

project_user
  project_id
  user_id
  role: admin | engineer | operator | viewer
```

Quy tắc membership:

- Tenant membership không tự động tạo Project membership.
- User thường chỉ nhìn thấy Project được gán trong `project_user`.
- Tenant owner/admin và platform admin được Policy cho phép quản lý Project
  thuộc phạm vi của mình.
- Project member được truy cập theo Project membership và role.
- Không nhận `tenant_id` hoặc `project_id` từ request body; scope lấy từ route
  binding và server-side authorization.

### 2.3 Asset

Asset là master-data node tenant-owned, bắt buộc thuộc một Project.

```text
assets
  id
  project_id NOT NULL
  asset_type: site | factory | area | production_line | station | equipment
  name
  code
  location nullable
  status: active | inactive
  created_at
  updated_at
```

Ràng buộc Asset:

- `project_id` là bắt buộc và không đổi qua Asset API.
- `code` unique trong phạm vi `project_id + asset_type`.
- Asset có thể là root hoặc có đúng một parent.
- Mọi loại Asset có thể chứa mọi loại Asset; phase này không áp đặt ma trận
  Site → Factory → Line.
- Không có bảng `equipments`, `factories`, `production_lines` mới cho domain
  hiện tại; các khái niệm này đều là `AssetType`.

## 3. Quan hệ Asset

`entity_relations` là nguồn sự thật duy nhất:

```text
entity_relations
  id
  project_id NOT NULL
  source_type = asset
  source_id = parent asset id
  target_type = asset
  target_id = child asset id
  relation_type = contains
  created_at
  updated_at
```

Ràng buộc quan hệ:

- Chỉ chấp nhận `asset contains asset`.
- Một Asset có tối đa một incoming `contains` relation.
- Parent và child phải cùng Project.
- Không được self-parenting.
- Không được tạo cycle.
- `parent_asset_id` trong request/resource là projection tương thích frontend,
  không phải cột lưu trữ và không được trở thành source thứ hai.

Khi xóa Asset:

1. Xóa các relation có Asset là source hoặc target.
2. Xóa Asset mục tiêu.
3. Các child còn lại được giữ nguyên và trở thành root.

## 4. API contract

### 4.1 Tenant API

```text
GET    /api/tenants
GET    /api/tenants/{tenant}
POST   /api/tenants
PATCH  /api/tenants/{tenant}
DELETE /api/tenants/{tenant}
```

Tenant update chỉ nhận:

```json
{
  "name": "Updated tenant",
  "code": "updated-tenant",
  "status": "active"
}
```

Không được cập nhật qua body:

- `parent_id`;
- `tenant_id`;
- `project_id`;
- membership role hoặc user ownership.

### 4.2 Project API

```text
GET    /api/tenants/{tenant}/projects
POST   /api/tenants/{tenant}/projects
GET    /api/tenants/{tenant}/projects/{project}
PATCH  /api/tenants/{tenant}/projects/{project}
DELETE /api/tenants/{tenant}/projects/{project}
```

Project create/update nhận các field:

```json
{
  "name": "Hanoi Plant",
  "description": "Production workspace",
  "status": "active"
}
```

`tenant_id` được lấy từ `{tenant}` và gắn ở persistence boundary. Không được
di chuyển Project sang Tenant khác qua Project API.

### 4.3 Asset API

```text
GET    /api/tenants/{tenant}/projects/{project}/assets
POST   /api/tenants/{tenant}/projects/{project}/assets
GET    /api/tenants/{tenant}/projects/{project}/assets/{asset}
PATCH  /api/tenants/{tenant}/projects/{project}/assets/{asset}
DELETE /api/tenants/{tenant}/projects/{project}/assets/{asset}
```

Asset create/update nhận:

```json
{
  "name": "Assembly Station 01",
  "code": "station-01",
  "asset_type": "station",
  "location": "Line A",
  "status": "active",
  "parent_asset_id": 12
}
```

`parent_asset_id` có ba trạng thái hợp lệ:

- không gửi khi không thay đổi parent trong PATCH;
- gửi số nguyên để set/thay parent;
- gửi `null` để gỡ parent và đưa Asset thành root.

Response vẫn trả `parent_asset_id`, nhưng backend đọc giá trị này từ
`entity_relations`.

## 5. Authorization và route binding

Tất cả route tenant/project/asset dùng authenticated context, scoped binding và
Policy. Resource thuộc Tenant hoặc Project khác phải trả `404`, không trả chi
tiết để tránh lộ existence.

| Resource | Read/list | Create/update | Delete |
|---|---|---|---|
| Tenant | tenant member | tenant owner/admin | tenant owner |
| Project | tenant member hoặc project member | tenant owner/admin | tenant owner/admin |
| Asset | tenant owner/admin hoặc project member | tenant owner/admin hoặc project admin/engineer | tenant owner/admin hoặc project admin |

Platform admin có quyền theo Policy hiện tại.

Request phải đi qua FormRequest và dùng dữ liệu đã validate. Không dùng
`tenant_id`, `project_id` do client gửi để quyết định scope.

## 6. Update và transaction rules

Các thao tác sau phải atomic:

- tạo Asset và tạo `contains` relation;
- cập nhật thông tin Asset và thay đổi parent;
- gỡ parent;
- xóa Asset và xóa các relation liên quan.

Thứ tự validate parent:

1. Asset parent tồn tại.
2. Parent cùng Project với child.
3. Parent không phải chính child.
4. Duyệt ancestor chain để từ chối cycle.
5. Xóa incoming relation cũ rồi tạo relation mới trong cùng transaction.

## 7. Out of scope

Không thêm vào implementation của spec này:

- Device model hoặc Device route;
- gateway/device relation;
- MQTT topic, credential, token rotate/revoke;
- firmware, last-seen, telemetry hoặc command;
- relation type ngoài `contains`;
- bảng Equipment riêng;
- Project chuyển Tenant hoặc Asset chuyển Project qua API.

## 8. Acceptance criteria

- User không thể truy cập Project ngoài Tenant/Project scope được cấp.
- Có thể CRUD Asset type `equipment` như các Asset type khác.
- API trả `parent_asset_id` nhưng database không còn cột `assets.parent_asset_id`.
- `entity_relations` là nguồn sự thật duy nhất cho hierarchy.
- Cross-project parent, self-parenting và cycle đều bị từ chối.
- Thay parent và gỡ parent cập nhật đúng một incoming `contains` relation.
- Xóa parent không xóa child.
- Không có model, route hoặc migration cho Device trong phase này.
