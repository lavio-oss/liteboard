# IT-2 — Device Profile và telemetry schema

> Trạng thái: kế hoạch; chưa có implementation telemetry schema/profile trong Data Plane.

## Mục tiêu

Cho developer khai báo trước dữ liệu hợp lệ của một nhóm thiết bị, để Device gửi sai field/type/range bị từ chối có lý do.

## Contract đề xuất

- Profile thuộc Project; Device được gán một Profile trong cùng Project.
- Profile chọn một Telemetry Schema có version cụ thể. Schema version đã phát hành là bất biến; thay đổi field tạo version mới.
- Mỗi field định nghĩa `type`, `unit`, `required`, và tùy chọn `min`/`max`. `strict_mode` quyết định có từ chối field không khai báo hay không.
- Chốt API, schema ownership, profile assignment và phiên bản tương thích trước khi tạo migration; không để schema vừa theo Project vừa có nguồn riêng trên Device mà không có quy tắc ưu tiên.

## Schema mẫu `motor-v1`

```yaml
code: motor-v1
version: 1
strict_mode: true
fields:
  temperature:
    type: float
    unit: celsius
    required: true
    min: -20
    max: 150
  vibration:
    type: float
    unit: mm/s RMS # xác nhận đơn vị/chuẩn đo với cảm biến pilot
    required: true
  rpm:
    type: integer
    unit: rpm
    required: true
    min: 0
```

## Nghiệm thu

- Có thể tạo/đọc schema và gán Profile/Device trong cùng Project; cross-Project assignment bị từ chối.
- Payload đúng kiểu và miền giá trị được chấp nhận; thiếu field bắt buộc, field lạ ở strict mode, type sai hoặc vượt min/max có reason code ổn định.
- Device gửi `schema_version` không được tự chọn schema khác Project/Profile của nó.
- Có compatibility note cho firmware đang gửi version cũ trước khi schema mới được bật.

## Ngoài phạm vi

Tự suy luận schema, dynamic arbitrary validation code và tự chuyển đổi đơn vị chưa được đưa vào phase này.
