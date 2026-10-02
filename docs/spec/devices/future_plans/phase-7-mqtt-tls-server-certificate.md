# Phase 7: MQTT TLS server-auth và xoay vòng chứng chỉ máy chủ

> Trạng thái: Kế hoạch vận hành — chưa nghiệm thu runtime hoặc quy trình xoay vòng.
> Thuộc kế hoạch: [future_device_security_and_ingestion_plan.md](./future_device_security_and_ingestion_plan.md)
> Phụ thuộc: MQTT Access Token auth/ACL hiện có và listener TLS `8883`.

## 1. Mục tiêu

Repository đã cấu hình listener TLS `8883` và ví dụ client xác minh CA. Cần xác nhận broker chạy thật bằng artifact đã pin, chứng chỉ có SAN khớp host thiết bị sử dụng, và quy trình thay chứng chỉ máy chủ không làm gián đoạn Device quá thời gian cho phép. Đây là TLS server-auth; Access Token vẫn là credential đăng nhập MQTT của Device.

## 2. Việc thực hiện

1. Pin broker/auth-plugin artifact; khởi chạy cấu hình production-equivalent và ghi nhận image/version, host, CA và SAN dùng trong bài kiểm tra.
2. Xác minh Device dùng Access Token hợp lệ kết nối/publish qua `8883`; token sai, CA sai, hostname sai và kết nối plaintext `1883`/`9001` đều bị từ chối.
3. Chuẩn bị phân phối CA/certificate chain cho simulator và firmware; ghi rõ thời gian hiệu lực và thời điểm phải cập nhật firmware khi CA thay đổi.
4. Thử xoay vòng chứng chỉ máy chủ trong môi trường kiểm thử: cấp chứng chỉ mới cho cùng hostname, cập nhật broker, xác minh client kết nối lại và khả năng xử lý khi chứng chỉ hết hạn.
5. Ghi kết quả runtime và tác động tenant/project: broker ACL vẫn giới hạn topic theo Device và Project sau reconnect, cache auth không cấp lại quyền cho token đã revoke.

## 3. Tiêu chí nghiệm thu

- [ ] Broker thật dùng đúng artifact đã pin; client hợp lệ xác minh CA/hostname và kết nối qua `8883`.
- [ ] Token/CA/hostname sai và plaintext `1883`/`9001` đều thất bại.
- [ ] Sau khi xoay vòng chứng chỉ máy chủ, client kết nối lại được bằng Access Token và ACL tenant/project vẫn đúng.
- [ ] Quy trình phân phối trust chain và khôi phục khi xoay vòng thất bại được ghi lại, thử nghiệm và có kết quả.
