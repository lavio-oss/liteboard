# MQTT IDS (nghiên cứu)

Hướng nghiên cứu phát hiện xâm nhập (IDS) cho lưu lượng MQTT. **Chưa thuộc luồng ingest, release hay cam kết bảo mật của LiteBoard.** Đây là POC offline; chỉ xem xét triển khai worker tùy chọn sau khi POC chạy được trên traffic của LiteBoard và vượt các cổng đánh giá.

## Ranh giới

- Màn theo dõi telemetry và cảnh báo nghiệp vụ là chức năng của LiteBoard, mô tả trong [industrial-telemetry](../industrial-telemetry/README.md). IDS là một năng lực bảo mật riêng và không gộp vào đường ingest của `telemetry-service`.
- Xác thực thiết bị thuộc [devices](../devices/README.md).

## Hiện trạng

Chưa có gì được triển khai, nên chưa có thư mục `current/`.

## Kế hoạch

| Tài liệu | Trạng thái |
| --- | --- |
| [Kế hoạch nghiên cứu MQTT IDS](./plan/mqtt-ids-poc.md) | Research / POC |
