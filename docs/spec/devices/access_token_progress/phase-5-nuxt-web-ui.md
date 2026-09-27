# Phase 5: Giao diện Web UI Quản lý Thiết bị & Access Token (`web/`)

> Thuộc kế hoạch: [access_token_plan.md](../access_token_plan.md)  
> Tầng kiến trúc: **Frontend Application (`Nuxt 4 / Nuxt UI — web/`)**  
> Phụ thuộc: [Phase 2](./phase-2-laravel-control-plane.md)  
> Trạng thái: `Pending`

---

## 1. Mục tiêu của Phase 5

Xây dựng giao diện quản lý Thiết bị (`Devices`) trên ứng dụng Nuxt 4 (`web/`), mang lại trải nghiệm trực quan như ThingsBoard:
1. Tạo, sửa, xóa `Device` trong phạm vi `Project` và gắn với `Equipment Asset`.
2. Cho phép nhập `Access Token` tùy chỉnh ngay khi tạo hoặc để hệ thống tự sinh.
3. Cung cấp nút **Copy Access Token**, hộp thoại **Manage Credentials** (Xem / Đổi token tùy chỉnh / Rotate token ngẫu nhiên mới / Revoke token).
4. Cung cấp hộp thoại **Check Connectivity** hiển thị trạng thái kết nối (`online`/`offline`, `last_seen_at`) kèm câu lệnh `mosquitto_pub` và code mẫu `ESP32` điền sẵn thông tin của thiết bị.

---

## 2. Hợp đồng Dữ liệu Frontend (`Zod Schemas`)

Tuân thủ [docs/DATA_CONTRACT_INTEGRITY_RULE.md](../../../DATA_CONTRACT_INTEGRITY_RULE.md) (không dùng `any`, dùng trực tiếp tên trường từ API Resource, validate bằng `Zod`):

Trong [web/app/types/admin.ts](../../../../web/app/types/admin.ts):
```ts
export const DeviceStatusSchema = z.enum(['active', 'inactive'])
export const DeviceConnectionStateSchema = z.enum(['online', 'offline', 'unstable'])
export const DeviceCredentialStatusSchema = z.enum(['active', 'revoked'])

export const DeviceMqttTopicsSchema = z.object({
  telemetry: z.string(),
  heartbeat: z.string(),
  commands: z.string(),
  commands_ack: z.string()
})

export const DeviceSchema = z.object({
  id: z.string().uuid(),
  project_id: z.string().uuid(),
  equipment_asset_id: z.string().uuid().nullable(),
  name: z.string(),
  code: z.string(),
  description: z.string().nullable(),
  status: DeviceStatusSchema,
  connection_state: DeviceConnectionStateSchema,
  credential_type: z.literal('access_token'),
  credential_status: DeviceCredentialStatusSchema,
  mqtt_topics: DeviceMqttTopicsSchema,
  last_seen_at: z.string().nullable(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable()
})

export const DeviceCredentialSchema = z.object({
  device_id: z.string().uuid(),
  project_id: z.string().uuid(),
  credential_type: z.literal('access_token'),
  access_token: z.string(),
  status: DeviceCredentialStatusSchema,
  mqtt_topics: DeviceMqttTopicsSchema,
  rotated_at: z.string().nullable(),
  revoked_at: z.string().nullable(),
  updated_at: z.string().nullable()
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
    - Nút **Copy Access Token** (gọi `getDeviceCredential` và copy thẳng vào clipboard).
    - Nút **Manage Credentials** (mở modal quản lý Access Token).
    - Nút **Check Connectivity** (mở modal hướng dẫn kết nối MQTT & kiểm tra trạng thái).
    - Nút **Edit** / **Delete** Device.

### 3.2. Modal Quản lý Credential & Kết nối (`web/app/components/admin/DeviceCredentialsModal.vue`)
Gồm 2 tab:
1. **Tab `Credentials` (Quản lý Access Token)**:
   - Hiển thị chuỗi `access_token` (có nút ẩn/hiện và nút **Copy**).
   - Nút **Regenerate (Rotate Token)**: gọi `POST .../credentials/rotate` để sinh token 20 ký tự mới ngay lập tức.
   - Cho phép nhập `access_token` tùy chỉnh (16–64 ký tự) và chuyển trạng thái `active` / `revoked` qua `PUT .../credentials`.
2. **Tab `Check Connectivity` (Kiểm tra Kết nối kiểu ThingsBoard)**:
   - Hiển thị trạng thái kết nối thực tế của thiết bị (`connection_state` & `last_seen_at`) kèm nút **Refresh Status**.
   - Hiển thị đoạn lệnh **Mosquitto CLI (`mosquitto_pub`)** đã điền sẵn `access_token` và topic `mqtt_topics.telemetry` của thiết bị.
   - Hiển thị đoạn code mẫu **ESP32 (Arduino `PubSubClient`)** đã điền sẵn `LB_PROJECT_ID`, `LB_DEVICE_ID`, `LB_ACCESS_TOKEN` để copy dán thẳng vào Arduino IDE / PlatformIO.

---

## 4. Danh sách File Cần Tạo / Sửa

1. **[MODIFY]** [web/app/types/admin.ts](../../../../web/app/types/admin.ts)
2. **[MODIFY]** [web/app/composables/useAdmin.ts](../../../../web/app/composables/useAdmin.ts)
3. **[NEW]** `web/app/components/admin/DeviceCredentialsModal.vue`
4. **[MODIFY]** [web/app/pages/devices.vue](../../../../web/app/pages/devices.vue)
5. **[NEW]** `web/app/pages/admin/devices.vue`

---

## 5. Checklist Nghiệm thu Phase 5

- [ ] Người dùng có thể tạo, sửa, xóa Device và chọn Equipment Asset thuộc cùng Project.
- [ ] Bấm **Copy Access Token** sao chép đúng token của thiết bị vào clipboard.
- [ ] Trong modal **Manage Credentials**, thực hiện **Rotate** và **Revoke / Activate** cập nhật ngay lập tức và đồng bộ với API backend.
- [ ] Trong tab **Check Connectivity**, lệnh `mosquitto_pub` và code `ESP32` sinh ra khớp chính xác với `access_token`, `project_id`, `device_id` của thiết bị đang chọn.
