# Phase 7: MQTT TLS server-auth và xoay vòng chứng chỉ máy chủ

> Trạng thái: TLS local trên broker, simulator và telemetry service đã được cấu hình; cấu hình production-equivalent, CA/SAN trên endpoint triển khai và quy trình xoay vòng chứng chỉ chưa được nghiệm thu.
> Thuộc kế hoạch: [future_device_security_and_ingestion_plan.md](./future_device_security_and_ingestion_plan.md)
> Phụ thuộc: MQTT Access Token auth/ACL hiện có và listener TLS `8883`.

## 1. Mục tiêu

Broker hiện cấu hình listener TLS `8883` và xác thực Access Token theo [luồng MQTT hiện hành](../devices_access_token.md#mqtt-authentication). Phase 7 tập trung kiểm tra cấu hình production-equivalent, phân phối CA và thay chứng chỉ máy chủ mà không làm gián đoạn Device quá thời gian cho phép. Đây là TLS server-auth; Access Token vẫn là credential đăng nhập MQTT của Device.

## 2. Việc thực hiện

1. Pin broker/auth-plugin artifact; khởi chạy cấu hình production-equivalent và ghi nhận image/version, host, CA và SAN dùng trong bài kiểm tra.
2. Xác minh Device dùng Access Token hợp lệ kết nối/publish qua `8883`; token sai, CA sai, hostname sai và kết nối plaintext `1883`/`9001` đều bị từ chối.
3. Chuẩn bị phân phối CA/certificate chain cho simulator và firmware; ghi rõ thời gian hiệu lực và thời điểm phải cập nhật firmware khi CA thay đổi.
4. Thử xoay vòng chứng chỉ máy chủ trong môi trường kiểm thử: cấp chứng chỉ mới cho cùng hostname, cập nhật broker, xác minh client kết nối lại và khả năng xử lý khi chứng chỉ hết hạn.
5. Ghi kết quả runtime và tác động tenant/project: broker ACL vẫn giới hạn topic theo Device và Project sau reconnect, cache auth không cấp lại quyền cho token đã revoke.

## 3. Tiêu chí nghiệm thu

- [ ] Trên cấu hình production-equivalent, xác nhận client hợp lệ dùng CA/hostname production; token/CA/hostname sai và plaintext `1883`/`9001` đều bị từ chối qua topology/firewall thực tế.
- [ ] Sau khi xoay vòng chứng chỉ máy chủ, client kết nối lại được bằng Access Token và ACL tenant/project vẫn đúng.
- [ ] Quy trình phân phối trust chain và khôi phục khi xoay vòng thất bại được ghi lại, thử nghiệm và có kết quả.
