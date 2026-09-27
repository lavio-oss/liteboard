# Phase 1: Database Schema & PostgreSQL Views (`backend/database/`)

> Thuộc kế hoạch: [access_token_plan.md](../access_token_plan.md)  
> Tầng kiến trúc: **Database & Storage Layer (`PostgreSQL 16`)**  
> Trạng thái: `Completed`

---

## 1. Mục tiêu của Phase 1

Thiết lập nền tảng lưu trữ dữ liệu cho **Device** và **Device Credential (Access Token)** trong PostgreSQL, đồng thời khởi tạo 2 SQL View (`v_mqtt_users` và `v_mqtt_acls`) để MQTT Broker (`mosquitto-go-auth`) có thể truy vấn xác thực và phân quyền topic trực tiếp mà không cần viết thêm tầng API trung gian.

---

## 2. Thiết kế Bảng Dữ liệu (Relational Schema)

### 2.1. Bảng `devices`

Lưu trữ danh tính kỹ thuật của thiết bị IoT thuộc một `Project` và liên kết tùy chọn tới một `Asset` có `asset_type = 'equipment'`.

```text
devices
  id                  uuid PRIMARY KEY
  project_id          uuid NOT NULL FK -> projects.id (ON UPDATE CASCADE, ON DELETE CASCADE)
  equipment_asset_id  uuid NULLABLE FK -> assets.id (ON UPDATE CASCADE, ON DELETE SET NULL)
  name                varchar(255) NOT NULL
  code                varchar(64) NOT NULL
  description         text NULLABLE
  status              varchar(32) NOT NULL DEFAULT 'active'       -- active | inactive
  connection_state    varchar(32) NOT NULL DEFAULT 'offline'      -- online | offline | unstable
  last_seen_at        timestamptz NULLABLE
  created_at          timestamptz
  updated_at          timestamptz

Constraints & Indexes:
  - UNIQUE (project_id, code)
  - INDEX  (project_id, status)
  - INDEX  (project_id, equipment_asset_id)
```

### 2.2. Bảng `device_credentials`

Mỗi `Device` có đúng 1 bản ghi `device_credentials` hiện hành (quan hệ 1-1 giống ThingsBoard). Lưu trữ đồng thời 3 biểu diễn của Access Token phục vụ cho 3 mục đích khác nhau:

```text
device_credentials
  id                  uuid PRIMARY KEY
  device_id           uuid NOT NULL UNIQUE FK -> devices.id (ON UPDATE CASCADE, ON DELETE CASCADE)
  credential_type     varchar(32) NOT NULL DEFAULT 'access_token' -- access_token
  token_hash          char(64) NOT NULL UNIQUE                    -- SHA-256 hex của plain token
  mqtt_password_hash  varchar(255) NOT NULL                       -- Bcrypt ($2a$) của plain token cho mosquitto-go-auth
  encrypted_token     text NOT NULL                               -- AES-256 encrypted string (Laravel Crypt) để hiển thị lại trên UI
  status              varchar(32) NOT NULL DEFAULT 'active'       -- active | revoked
  rotated_at          timestamptz NULLABLE
  revoked_at          timestamptz NULLABLE
  created_at          timestamptz
  updated_at          timestamptz

Constraints & Indexes:
  - UNIQUE (device_id)
  - UNIQUE (token_hash)
```

---

## 3. Thiết kế PostgreSQL Views cho MQTT Broker (`v_mqtt_users` & `v_mqtt_acls`)

### 3.1. View `v_mqtt_users`

Chỉ trả về các Access Token đang `active` và thuộc chuỗi `Device` → `Project` → `Tenant` đều đang `active`. Nếu bất kỳ cấp nào bị chuyển sang `inactive` hoặc `revoked`, thiết bị tự động biến mất khỏi View này và bị chặn kết nối MQTT.

```sql
CREATE OR REPLACE VIEW v_mqtt_users AS
SELECT
    dc.token_hash,
    dc.mqtt_password_hash,
    d.id AS device_id,
    d.project_id
FROM device_credentials dc
JOIN devices d ON d.id = dc.device_id
JOIN projects p ON p.id = d.project_id
JOIN tenants t ON t.id = p.tenant_id
WHERE dc.status = 'active'
  AND d.status = 'active'
  AND p.status = 'active'
  AND t.status = 'active';
```

### 3.2. View `v_mqtt_acls`

Sinh động danh sách topic hợp lệ cho từng `token_hash` theo chuẩn `rw` của `mosquitto-go-auth` (`1` = read, `2` = write/publish, `4` = subscribe):

```sql
CREATE OR REPLACE VIEW v_mqtt_acls AS
SELECT
    u.token_hash,
    'liteboard/' || u.project_id || '/devices/' || u.device_id || '/telemetry' AS topic,
    2 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT
    u.token_hash,
    'liteboard/' || u.project_id || '/devices/' || u.device_id || '/heartbeat' AS topic,
    2 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT
    u.token_hash,
    'liteboard/' || u.project_id || '/devices/' || u.device_id || '/commands/ack' AS topic,
    2 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT
    u.token_hash,
    'liteboard/' || u.project_id || '/devices/' || u.device_id || '/commands' AS topic,
    1 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT
    u.token_hash,
    'liteboard/' || u.project_id || '/devices/' || u.device_id || '/commands' AS topic,
    4 AS rw
FROM v_mqtt_users u;
```

---

## 4. Danh sách File Đã Tạo (Tách riêng từng bảng/view)

1. **[NEW]** [2026_09_27_000001_create_devices_table.php](../../../../backend/database/migrations/2026_09_27_000001_create_devices_table.php)
   - Tạo bảng `devices` (forward-only, không có `down()`).
2. **[NEW]** [2026_09_27_000002_create_device_credentials_table.php](../../../../backend/database/migrations/2026_09_27_000002_create_device_credentials_table.php)
   - Tạo bảng `device_credentials` (forward-only, không có `down()`).
3. **[NEW]** [2026_09_27_000003_create_mqtt_views.php](../../../../backend/database/migrations/2026_09_27_000003_create_mqtt_views.php)
   - Tạo 2 SQL Views `v_mqtt_users` và `v_mqtt_acls`.
4. **[NEW]** [DeviceFactory.php](../../../../backend/database/factories/DeviceFactory.php)
   - Hỗ trợ tạo nhanh dữ liệu mẫu `Device` kèm `DeviceCredential` cho Feature Tests và Database Seeder.

---

## 5. Checklist Nghiệm thu Phase 1

- [x] Tách riêng từng bảng/view thành 3 file migration độc lập.
- [x] Kiểm tra truy vấn `SELECT * FROM v_mqtt_users` và `SELECT * FROM v_mqtt_acls` trả về đúng topic namespace `liteboard/{project_id}/devices/{device_id}/*`.
- [x] Khi xóa `Project`, toàn bộ `devices` và `device_credentials` bên dưới tự động cascade delete; khi xóa `Asset` (`equipment`), cột `devices.equipment_asset_id` tự động về `NULL` (`nullOnDelete`).
