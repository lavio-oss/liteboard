# Phase 2: Laravel Control Plane — SubModule `Device` (`backend/modules/Admin/`)

> Thuộc kế hoạch: [access_token_plan.md](../access_token_plan.md)  
> Tầng kiến trúc: **Backend Control Plane (`Laravel 13 — ADR Architecture`)**  
> Phụ thuộc: [Phase 1](./phase-1-database-and-sql-views.md)  
> Trạng thái: `Completed`

---

## 1. Mục tiêu của Phase 2

Xây dựng đầy đủ SubModule `Device` trong `backend/modules/Admin/` theo kiến trúc **Action-Domain-Responder (ADR)**, cung cấp REST API quản lý vòng đời thiết bị, liên kết với Equipment Asset, và quản lý Access Token (xem, copy, nhập token tùy chỉnh, rotate, revoke).

---

## 2. Cấu trúc Thư mục SubModule `Device`

```text
backend/modules/Admin/
├── Actions/Device/
│   └── Http/
│       ├── ListTenantDevicesAction.php
│       ├── ListDevicesAction.php
│       ├── CreateDeviceAction.php
│       ├── ShowDeviceAction.php
│       ├── UpdateDeviceAction.php
│       ├── DeleteDeviceAction.php
│       ├── ShowDeviceCredentialAction.php
│       ├── UpdateDeviceCredentialAction.php
│       └── RotateDeviceCredentialAction.php
├── Enums/Device/
│   ├── DeviceStatus.php
│   ├── DeviceConnectionState.php
│   ├── DeviceCredentialType.php
│   └── DeviceCredentialStatus.php
├── Models/Device/
│   ├── Device.php
│   └── DeviceCredential.php
├── Policies/Device/
│   └── DevicePolicy.php
├── Requests/Device/
│   ├── TenantDeviceIndexRequest.php
│   ├── DeviceIndexRequest.php
│   ├── ShowDeviceRequest.php
│   ├── StoreDeviceRequest.php
│   ├── UpdateDeviceRequest.php
│   ├── DeleteDeviceRequest.php
│   ├── ShowDeviceCredentialRequest.php
│   ├── UpdateDeviceCredentialRequest.php
│   └── RotateDeviceCredentialRequest.php
├── Resources/Device/
│   ├── DeviceResource.php
│   └── DeviceCredentialResource.php
├── Services/Device/
│   ├── DeviceCredentialService.php
│   ├── DevicePersistenceService.php
│   └── DeviceQueryService.php
└── Routes/
    └── device.php
```

---

## 3. Quy tắc Phân quyền (`DevicePolicy`)

| Quyền (Policy Method) | Đối tượng được phép |
|---|---|
| `viewAny` / `view` | `Platform Admin`, `Tenant Owner`, `Tenant Admin`, hoặc bất kỳ `Project Member` nào (`admin`, `engineer`, `operator`, `viewer`) |
| `create` / `update` | `Platform Admin`, `Tenant Owner`, `Tenant Admin`, hoặc Project `admin` / `engineer` |
| `manageCredentials` | `Platform Admin`, `Tenant Owner`, `Tenant Admin`, hoặc Project `admin` / `engineer` (Project `operator` và `viewer` **không** được xem hay đổi Access Token) |
| `delete` | `Platform Admin`, `Tenant Owner`, `Tenant Admin`, hoặc Project `admin` |

---

## 4. Hợp đồng API (API & Data Contract)

### 4.1. Danh sách Endpoints

```text
GET    /api/tenants/{tenant}/devices
GET    /api/tenants/{tenant}/projects/{project}/devices
POST   /api/tenants/{tenant}/projects/{project}/devices
GET    /api/tenants/{tenant}/projects/{project}/devices/{device}
PATCH  /api/tenants/{tenant}/projects/{project}/devices/{device}
DELETE /api/tenants/{tenant}/projects/{project}/devices/{device}

GET    /api/tenants/{tenant}/projects/{project}/devices/{device}/credentials
PUT    /api/tenants/{tenant}/projects/{project}/devices/{device}/credentials
POST   /api/tenants/{tenant}/projects/{project}/devices/{device}/credentials/rotate
```

### 4.2. Request Payload Contract

- **`POST /api/tenants/{tenant}/projects/{project}/devices` (`StoreDeviceRequest`)**:
  ```json
  {
    "name": "Kiln Sensor Node 01",
    "code": "kiln-sensor-01",
    "description": "ESP32 sensor node",
    "equipment_asset_id": "019c0000-0000-7000-8000-000000000010",
    "status": "active",
    "access_token": "0bszem6ntw2bi0d28q1a"
  }
  ```
  - `equipment_asset_id`: `nullable`, `uuid`, phải thuộc cùng `{project}` và có `asset_type = 'equipment'`.
  - `access_token`: `sometimes`, `nullable`, `string`, `min:16`, `max:64`, `regex:/^[A-Za-z0-9_-]+$/`. Nếu không gửi hoặc gửi `null`, `DeviceCredentialService` tự sinh chuỗi 20 ký tự `[a-z0-9]`. Kiểm tra không trùng `token_hash` trong `withValidator()`.
  - `tenant_id`, `project_id`: `prohibited`.

- **`PUT /api/tenants/{tenant}/projects/{project}/devices/{device}/credentials` (`UpdateDeviceCredentialRequest`)**:
  ```json
  {
    "access_token": "custom_token_20_chars",
    "status": "active"
  }
  ```
  - Cho phép cập nhật `access_token` tùy chỉnh và/hoặc đổi `status` (`active` | `revoked`).

- **`POST /api/tenants/{tenant}/projects/{project}/devices/{device}/credentials/rotate` (`RotateDeviceCredentialRequest`)**:
  - Body rỗng; hệ thống tự động sinh một `access_token` 20 ký tự mới, cập nhật `token_hash`, `mqtt_password_hash`, `encrypted_token`, `rotated_at = CarbonImmutable::now()`, `status = 'active'`, `revoked_at = null`.

### 4.3. Response Resource Contract

- **`DeviceResource`** (không trả về `access_token` plain text ở API danh sách/chi tiết chung để tránh lộ token khi `viewer`/`operator` xem danh sách):
  ```json
  {
    "id": "019c0000-0000-7000-8000-000000000100",
    "project_id": "019c0000-0000-7000-8000-000000000002",
    "equipment_asset_id": "019c0000-0000-7000-8000-000000000010",
    "name": "Kiln Sensor Node 01",
    "code": "kiln-sensor-01",
    "description": "ESP32 sensor node",
    "status": "active",
    "connection_state": "offline",
    "credential_type": "access_token",
    "credential_status": "active",
    "mqtt_topics": {
      "telemetry": "liteboard/019c0000-0000-7000-8000-000000000002/devices/019c0000-0000-7000-8000-000000000100/telemetry",
      "heartbeat": "liteboard/019c0000-0000-7000-8000-000000000002/devices/019c0000-0000-7000-8000-000000000100/heartbeat",
      "commands": "liteboard/019c0000-0000-7000-8000-000000000002/devices/019c0000-0000-7000-8000-000000000100/commands",
      "commands_ack": "liteboard/019c0000-0000-7000-8000-000000000002/devices/019c0000-0000-7000-8000-000000000100/commands/ack"
    },
    "last_seen_at": null,
    "created_at": "2026-09-27T00:00:00.000000Z",
    "updated_at": "2026-09-27T00:00:00.000000Z"
  }
  ```

- **`DeviceCredentialResource`** (chỉ trả về ở các endpoint `/credentials` yêu cầu quyền `manageCredentials`):
  ```json
  {
    "device_id": "019c0000-0000-7000-8000-000000000100",
    "project_id": "019c0000-0000-7000-8000-000000000002",
    "credential_type": "access_token",
    "access_token": "0bszem6ntw2bi0d28q1a",
    "status": "active",
    "mqtt_topics": {
      "telemetry": "liteboard/019c0000-0000-7000-8000-000000000002/devices/019c0000-0000-7000-8000-000000000100/telemetry",
      "heartbeat": "liteboard/019c0000-0000-7000-8000-000000000002/devices/019c0000-0000-7000-8000-000000000100/heartbeat",
      "commands": "liteboard/019c0000-0000-7000-8000-000000000002/devices/019c0000-0000-7000-8000-000000000100/commands",
      "commands_ack": "liteboard/019c0000-0000-7000-8000-000000000002/devices/019c0000-0000-7000-8000-000000000100/commands/ack"
    },
    "rotated_at": "2026-09-27T00:00:00.000000Z",
    "revoked_at": null,
    "updated_at": "2026-09-27T00:00:00.000000Z"
  }
  ```

---

## 5. Logic Xử lý Token trong `DeviceCredentialService`

- **Sinh Token ngẫu nhiên**:
  - Bảng ký tự: `0123456789abcdefghijklmnopqrstuvwxyz` (36 ký tự, độ dài 20 → $36^{20}$ tổ hợp).
  - Kiểm tra không trùng `token_hash` trong bảng `device_credentials`.
- **Tạo bộ băm & mã hóa**:
  - `token_hash`: `hash('sha256', $plainToken)`
  - `mqtt_password_hash`: `str_replace('$2y$', '$2a$', Hash::make($plainToken))` (chuẩn hóa tiền tố `$2a$` cho thư viện Go `bcrypt` của `mosquitto-go-auth`).
  - `encrypted_token`: được cast tự động bởi Laravel `'encrypted'`.

---

## 6. Checklist Nghiệm thu Phase 2

- [x] Viết đầy đủ Feature Tests trong `backend/tests/Feature/DeviceAuthorizationAndCredentialTest.php`:
  - [x] Tạo Device tự sinh Access Token 20 ký tự và tạo Device với Access Token tùy chỉnh.
  - [x] Gắn Device với Asset loại `equipment` thành công; từ chối (`422`) nếu Asset thuộc Project khác hoặc không phải `equipment`.
  - [x] Kiểm tra role matrix: `admin`/`engineer` xem và rotate/revoke được Access Token; `operator`/`viewer` chỉ xem được Device nhưng bị chặn (`403`) khi truy cập `/credentials`.
  - [x] Kiểm tra cross-tenant và cross-project trả về `404`.
- [x] Chạy `cd backend && composer stan` đạt 0 lỗi.
- [x] Chạy `cd backend && composer test` pass toàn bộ test suite của Phase 1 & Phase 2 (`10 passed`, `95 assertions`).
- [x] Chạy `cd backend && composer pint` sau khi PHPStan và Tests đã pass.
