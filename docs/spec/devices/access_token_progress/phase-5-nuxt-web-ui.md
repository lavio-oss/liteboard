# Phase 5: Giao diện Web UI Quản lý Thiết bị & Access Token (`web/`)

> Thuộc kế hoạch: [access_token_plan.md](../access_token_plan.md)  
> Tầng kiến trúc: **Frontend Application (`Nuxt 4 / Nuxt UI — web/`)**  
> Phụ thuộc: [Phase 2](./phase-2-laravel-control-plane.md)  
> Trạng thái: `Completed`

---

## 1. Mục tiêu của Phase 5

Xây dựng giao diện quản lý Thiết bị (`Devices`) trên ứng dụng Nuxt 4 (`web/`), mang lại trải nghiệm trực quan như ThingsBoard:
1. Tạo, sửa, xóa `Device` trong phạm vi `Project` và gắn với `Equipment Asset`.
2. Để backend tự sinh Access Token khi tạo thiết bị và khi rotate.
3. Cung cấp nút **Copy Access Token**, hộp thoại **Manage Credentials** (Xem / Rotate token / Revoke hoặc kích hoạt lại).
4. Cung cấp hộp thoại **Check Connectivity** hiển thị trạng thái kết nối (`online`/`offline`, `last_seen_at`) kèm câu lệnh `mosquitto_pub` và code mẫu `ESP32` điền sẵn thông tin của thiết bị.

---

## 2. Hợp đồng Dữ liệu Frontend (`Zod Schemas`)

Tuân thủ [docs/DATA_CONTRACT_INTEGRITY_RULE.md](../../../DATA_CONTRACT_INTEGRITY_RULE.md) (không dùng `any`, dùng trực tiếp tên trường từ API Resource, validate bằng `Zod`):

Trong [web/app/types/admin.ts](../../../../web/app/types/admin.ts):
```ts
export const deviceConnectionStateSchema = z.enum(['online', 'offline', 'unstable'])
export const deviceCredentialTypeSchema = z.enum(['access_token'])
export const deviceCredentialStatusSchema = z.enum(['active', 'revoked'])

export const deviceMqttTopicsSchema = z.object({
  telemetry: z.string(),
  attributes: z.string(),
  command: z.string(),
  command_ack: z.string(),
  heartbeat: z.string()
})

export const deviceSchema = resourceBaseSchema.extend({
  project_id: z.string().uuid(),
  equipment_asset_id: z.string().uuid().nullable(),
  code: z.string(),
  description: z.string().nullable(),
  connection_state: deviceConnectionStateSchema,
  credential_type: deviceCredentialTypeSchema,
  credential_status: deviceCredentialStatusSchema,
  mqtt_topics: deviceMqttTopicsSchema,
  last_seen_at: z.string().nullable()
})

export const deviceCredentialSchema = z.object({
  device_id: z.string().uuid(),
  project_id: z.string().uuid(),
  credential_type: deviceCredentialTypeSchema,
  access_token: z.string(),
  status: deviceCredentialStatusSchema,
  mqtt_topics: deviceMqttTopicsSchema,
  rotated_at: z.string().nullable(),
  revoked_at: z.string().nullable(),
  updated_at: z.string()
})
```

---

## 3. Thiết kế Màn hình & Components

### 3.1. Bảng Danh sách Thiết bị (`web/app/pages/devices.vue` & `web/app/pages/admin/devices.vue`)
- **Bộ lọc (Filters)**:
  - Chọn `Project` (bắt buộc hoặc tất cả Project trong Tenant).
  - Lọc theo `status` (`active` / `inactive`) và `connection_state` (`online` / `offline` / `unstable`).
  - Tìm kiếm theo tên hoặc mã (`code`).
- **Các cột hiển thị**:
  - `Name` & `Code`
  - `Project`
  - `Equipment Asset` (hiển thị tên Equipment được gắn nếu có)
  - `Connection State` (Badge màu xanh cho `online`, xám cho `offline`, vàng cho `unstable`, kèm thời gian `last_seen_at`)
  - `Credential Status` (`active` / `revoked`)
  - **Actions**:
    - Nút **Manage Credentials** (mở modal quản lý Access Token & Check Connectivity).
    - Nút **Edit** / **Delete** Device.

### 3.2. Modal Quản lý Credential & Kết nối (`web/app/components/admin/DeviceCredentialsModal.vue`)
1. **Quản lý Access Token**:
   - Hiển thị chuỗi `access_token` (có nút ẩn/hiện và nút **Copy**).
   - Nút **Rotate token**: gọi `POST .../credentials/rotate` để sinh token 20 ký tự mới ngay lập tức.
   - Chỉ chuyển trạng thái `active` / `revoked` qua `PUT .../credentials`; không có giao diện nhập token tùy chỉnh.
2. **Kiểm tra Kết nối kiểu ThingsBoard (MQTT Topics & Quick Test)**:
   - Hiển thị trạng thái kết nối thực tế của thiết bị (`connection_state`).
   - Hiển thị danh sách đầy đủ `mqtt_topics` (`telemetry`, `heartbeat`, `attributes`, `command`, `command_ack`) kèm nút Copy.
   - Hiển thị đoạn lệnh **Mosquitto CLI (`mosquitto_pub`)** đã điền sẵn `access_token` và topic `mqtt_topics.telemetry` của thiết bị.
   - Hiển thị đoạn code mẫu **ESP32 (Arduino `PubSubClient`)** đã điền sẵn `LB_PROJECT_ID`, `LB_DEVICE_ID`, `LB_ACCESS_TOKEN`, `LB_TELEMETRY_TOPIC`.

---

## 4. Danh sách File Cần Tạo / Sửa

1. **[MODIFY]** [web/app/types/admin.ts](../../../../web/app/types/admin.ts)
2. **[MODIFY]** [web/app/composables/useAdmin.ts](../../../../web/app/composables/useAdmin.ts)
3. **[MODIFY]** [web/app/components/admin/AdminResourceFormModal.vue](../../../../web/app/components/admin/AdminResourceFormModal.vue)
4. **[NEW]** [web/app/components/admin/DeviceCredentialsModal.vue](../../../../web/app/components/admin/DeviceCredentialsModal.vue)
5. **[MODIFY]** [web/app/pages/devices.vue](../../../../web/app/pages/devices.vue)
6. **[NEW]** [web/app/pages/admin/devices.vue](../../../../web/app/pages/admin/devices.vue)
7. **[MODIFY]** [web/app/layouts/default.vue](../../../../web/app/layouts/default.vue)

---

## 5. Checklist Nghiệm thu Phase 5

- [x] Người dùng có thể tạo, sửa, xóa Device và chọn Equipment Asset thuộc cùng Project.
- [x] Bấm **Copy Access Token** sao chép đúng token của thiết bị vào clipboard.
- [x] Trong modal **Manage Credentials**, thực hiện **Rotate** và **Revoke / Activate** cập nhật ngay lập tức và đồng bộ với API backend.
- [x] Lệnh `mosquitto_pub` và code `ESP32` sinh ra khớp chính xác với `access_token`, `project_id`, `device_id` của thiết bị đang chọn.
