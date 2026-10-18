# Xác thực và phân quyền

Mảng này gồm ba chủ đề: xác thực người dùng của Laravel Control Plane (Sanctum cho trình duyệt và personal access token), phân quyền theo Tenant/Project bằng Policy, và cách backend với telemetry-service tin nhau.

## Ranh giới

- Xác thực **thiết bị** qua MQTT (Access Token, ACL của broker) thuộc [devices](../devices/README.md), không thuộc mảng này.
- Ranh giới dữ liệu Tenant, Project, Asset thuộc [tenant](../tenant/README.md); mảng này mô tả quyền truy cập đặt lên các ranh giới đó.

## Hiện trạng

| Tài liệu | Nội dung | Đối chiếu |
| --- | --- | --- |
| [Authentication](./current/authentication.md) | Sanctum, browser authentication, personal access token, security boundary | 2026-09-21 |
| [Access control](./current/access-control.md) | Phân loại dữ liệu, tenant và membership, Policy, API authorization, route binding, audit | 2026-09-23 |
| [Backend ↔ Telemetry service](./current/telemetry-service-auth.md) | Hai bên kết nối bằng gì, luồng xác thực, điểm yếu đã thấy, hướng làm chặt hơn | 2026-10-18 |

**Lưu ý lỗi thời:** mục "Equipment/MQTT: hiện trạng" của [Authentication](./current/authentication.md) ghi xác thực MQTT chưa triển khai. Điều đó không còn đúng: Access Token và MQTT auth/ACL đã có, xem [devices](../devices/current/devices_access_token.md).

## Kế hoạch

Chưa có thư mục `plan/`. Phần chưa làm được liệt kê ở cuối từng tài liệu hiện trạng: "Phần còn lại" trong access control và "Hướng làm chặt hơn (chưa thực hiện)" trong telemetry-service auth.
