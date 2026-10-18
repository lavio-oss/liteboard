# Tenant, Project và Asset

Tenant là ranh giới cô lập của hệ thống. Project, Asset (cùng Device, Profile và telemetry của Device) thuộc Tenant qua quan hệ Project. Mảng này mô tả dữ liệu central và dữ liệu thuộc Tenant, quyền truy cập và API của chuỗi Tenant, Project, Asset.

## Ranh giới

- Device và liên kết Device với Equipment Asset thuộc [devices](../devices/README.md).
- Authorization chi tiết của từng resource thuộc [auth](../auth/README.md); mảng này chỉ nêu ranh giới dữ liệu và quy tắc route binding của chuỗi Tenant, Project, Asset.

## Hiện trạng

| Tài liệu | Nội dung | Đối chiếu |
| --- | --- | --- |
| [Tenant, Project và Asset Master Data](./current/tenant-project-asset.md) | Ranh giới dữ liệu, quan hệ Asset, API, authorization, quy tắc update/transaction, acceptance criteria | 2026-09-23 |
| [Tenant management](./current/tenant-management.md) | Dữ liệu central của Tenant, data contract, API, Policy, frontend, contract V1 | Xem đầu tài liệu |

## Kế hoạch

Chưa có thư mục `plan/`. Phần ngoài phạm vi của contract Tenant, Project, Asset nằm ở mục "Out of scope" của [tài liệu đó](./current/tenant-project-asset.md).
