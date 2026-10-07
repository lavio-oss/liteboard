# Roadmap telemetry công nghiệp end-to-end

> Trạng thái: kế hoạch theo feature; chưa phải cam kết các phase đã triển khai.
> Phạm vi: một vertical slice từ Device thuộc Project đến telemetry, rule, alert và event giao cho EAMO.

## Mục tiêu

Làm rõ và triển khai theo từng phần use case sau:

```text
Tenant / Project
  → Factory / Production Line / Equipment
  → Device + Motor Profile + versioned telemetry schema
  → MQTT TLS → validation → PostgreSQL time-series records
  → REST / WebSocket / processing trace
  → rule → alert → versioned event / webhook → EAMO
```

Developer path mong muốn:

```text
Create Project
  → register Equipment and Device
  → assign profile and get Access Token
  → connect through MQTT
  → publish schema-valid telemetry
  → query it through REST and receive authorized WebSocket updates
  → observe a rule alert and an EAMO event
```

## Domain và contract khởi đầu

- Project và các tài nguyên bên dưới thuộc Tenant. Không nhận quyền từ `project_id` hoặc `device_id` trong topic/payload; phân quyền phải dựa trên Device credential và quan hệ Device–Project phía server.
- Tái sử dụng Asset graph hiện có. Đợt đầu biểu diễn Motor bằng một `Equipment` Asset (ví dụ `MOTOR-01`) dưới Factory/Production Line. Device (ví dụ `motor-01`) là danh tính MQTT riêng và liên kết tùy chọn tới Equipment; không tạo bảng Motor riêng trong vertical slice này.
- Tạo Project-owned Device Profile (ví dụ `motor-v1`) để chọn một version bất biến của Telemetry Schema. Device được gán Profile. Đây là quyết định thiết kế đề xuất; trước khi code phải đối chiếu lại với data contract và schema hiện hành để không tạo hai nguồn sự thật.
- Schema khai báo tên field, kiểu, đơn vị, bắt buộc hay tùy chọn và miền giá trị. Đơn vị/miền của vibration phải được xác nhận theo cảm biến và quy ước kỹ thuật của pilot.
- Giữ MQTT topic contract hiện hành: `liteboard/{project_id}/devices/{device_id}/telemetry`. Topic thân thiện như `factory/motor-01/telemetry` chỉ được dùng nếu có thay đổi contract và ACL được đặc tả riêng.
- PostgreSQL là điểm bắt đầu cho dữ liệu bền vững. Chỉ thêm TimescaleDB/partitioning khi benchmark chứng minh nhu cầu.
- LiteBoard phát alert/event về trạng thái thiết bị. EAMO sở hữu work order, phân công kỹ thuật viên và lịch sử bảo trì.

## Các feature phase

| Track | Feature | Kết quả chính | Trạng thái |
|---|---|---|---|
| Track | Feature | Kết quả chính | Plan tổng thể liên quan | Trạng thái |
|---|---|---|---|---|
| IT-1 | Project, Equipment và Device binding | Motor được mô hình hóa bằng Equipment; Device thuộc Project và liên kết đúng Equipment. Tái sử dụng Project/Asset/Device/Access Token hiện có. | Phase 1 | Nền tảng hiện có; cần nghiệm thu theo demo công nghiệp. |
| IT-2 | Device Profile và Telemetry Schema | Khai báo schema versioned cho motor, gán Profile cho Device và từ chối field sai. | Phase 3 | Kế hoạch. |
| IT-3 | MQTT ingest và lưu bền vững | Xác thực, validate envelope, chống duplicate ở database và lưu telemetry. | Phase 2–3 | Kế hoạch; chưa có telemetry persistence. |
| IT-4 | REST, WebSocket và trace | Query theo thời gian; nhận cập nhật realtime có phân quyền; xem trạng thái từng bước xử lý. | Phase 4 | Kế hoạch. |
| IT-5 | Rule, alert và webhook | `temperature > 80` liên tục 30 giây tạo một alert `CRITICAL`, phát được event retryable. | Phase 5 | Kế hoạch. |
| IT-6 | EAMO event contract | Gửi event versioned, idempotent và có nguồn gốc về Equipment/telemetry. | Phase 8 — EAMO/MES | Kế hoạch. |

Các mã `IT-*` chỉ thuộc roadmap này, tránh nhầm với số Phase của roadmap chính và roadmap hardening Device.

## Demo acceptance cuối vertical slice

1. Tạo `factory-demo` trong một Tenant; tạo Factory, Production Line và Equipment `MOTOR-01` trong Project.
2. Tạo Device `motor-01`, liên kết tới `MOTOR-01`, gán `motor-v1` và lấy Access Token theo flow hiện hành.
3. Device publish message có `message_id`, `schema_version`, `occurred_at`, `values` qua MQTT TLS; telemetry hợp lệ được ghi một lần, bản tin trùng không tạo sample thứ hai.
4. Người có quyền trong Project đọc sample qua REST và nhận WebSocket update; không thể đọc dữ liệu Project khác.
5. Dữ liệu nhiệt độ vượt `80 °C` theo duration đã định tạo một alert `CRITICAL`; retry không nhân đôi alert hoặc event.
6. EAMO nhận event có định danh Equipment, Device, Project/Tenant, thời điểm, severity, idempotency key và thông tin nguồn telemetry.
7. Báo cáo sent/accepted/rejected/duplicate, lỗi được thử, recovery và p50/p95/p99 đã đo. Không ghi các số latency ví dụ thành cam kết trước khi benchmark.

## Giới hạn của roadmap

- Không thay Access Token hoặc topic ACL bằng ID thân thiện do client gửi.
- Không đưa work order, phân công technician, hay maintenance history của EAMO vào LiteBoard.
- Không triển khai rule arbitrary code, dashboard builder tổng quát, ML/predictive maintenance, hoặc TimescaleDB trước khi có benchmark chứng minh cần thiết.
- Mỗi feature phase phải có tenant-isolation coverage và tình huống lỗi tương ứng; các cổng runtime là bằng chứng riêng với việc tài liệu/code đã tồn tại.

## Phase documents

- [IT-1 — Project, Equipment và Device binding](./phase-01-project-equipment-device.md)
- [IT-2 — Device Profile và telemetry schema](./phase-02-device-profile-schema.md)
- [IT-3 — MQTT ingestion và time-series persistence](./phase-03-mqtt-ingestion-storage.md)
- [IT-4 — REST, WebSocket và message trace](./phase-04-rest-websocket-trace.md)
- [IT-5 — Rule, alert và webhook](./phase-05-rules-alerts-webhook.md)
- [IT-6 — Event contract với EAMO](./phase-06-eamo-integration.md)

## Tài liệu nền

- [Kế hoạch tổng thể LiteBoard](../../plan.md)
- [Tenant, Project và Asset contract](../tenant/tenant-project-asset.md)
- [Device Access Token và provisioning](../devices/devices_access_token.md)
- [Hardening Access Token, TLS và ingestion](../devices/access_token_dev_plan/future_device_security_and_ingestion_plan.md)
