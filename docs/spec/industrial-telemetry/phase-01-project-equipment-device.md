# IT-1 — Project, Equipment và Device binding

> Trạng thái: dùng lại implementation hiện có; cần nghiệm thu với use case công nghiệp.

## Mục tiêu

Đặt thiết bị motor vào đúng Tenant/Project và Equipment mà không nhập nhằng Device identity với Equipment master data.

## Phạm vi

- Tạo một Project demo `factory-demo` trong Tenant.
- Dùng Asset graph hiện có để tạo Factory → Production Line → Equipment `MOTOR-01`.
- Tạo Device `motor-01`, liên kết `equipment_asset_id` tới `MOTOR-01`; lấy credentials qua flow Access Token hiện hành.
- Kiểm tra API/UI dùng tenant context và Project authorization cho từng thao tác.

Project, Asset hierarchy, Device management, credential và provisioning là nền tảng đã có trong repository; phase này không tạo lại các resource đó. `Equipment` vẫn là Asset type tổng quát. Nếu cần phân loại Motor/Pump sâu hơn, phần khác biệt thuộc Profile/schema hoặc metadata có contract, không tự thêm bảng Motor/Pump.

## Nghiệm thu

- Người có quyền tạo Project, Asset graph và Device trong scope được cấp; Device detail hiển thị Equipment association.
- Từ chối gắn Equipment thuộc Project khác hoặc Asset không phải Equipment.
- User/Device ở Tenant khác không đọc hoặc thay đổi Project, Device hay association này.
- Access Token chỉ cấp topic của Device được liên kết; provisioning vẫn chờ approval như contract hiện hành.

## Ngoài phạm vi

Telemetry schema, lưu sample, rule và EAMO event thuộc các phase sau.
