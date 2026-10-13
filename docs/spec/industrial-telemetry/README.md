# Roadmap telemetry công nghiệp end-to-end

> Trạng thái: IT-1/IT-2 có implementation; IT-3 ingestion code và tests đã có, live integration/L0 gate còn mở; IT-4 trở đi vẫn là roadmap.
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
- Profile Version đã phát hành là append-only qua API/model; Profile có version không thể xóa và số version không tái sử dụng. Archive/retirement để chặn assignment mới chưa có; cần quyết định khi có nhu cầu vận hành.
- Schema khai báo tên field, kiểu, đơn vị, bắt buộc hay tùy chọn và miền giá trị. Đơn vị/miền của vibration phải được xác nhận theo cảm biến và quy ước kỹ thuật của pilot.
- Mỗi telemetry sample là tenant-owned qua Device → Project → Tenant. Persist `project_id`/`device_id` và Profile Version ID đã validate; resolve Tenant qua Project, không dựa vào IDs từ payload. Nếu tenant ID được materialize để tối ưu, ràng buộc nó với Project trong database.
- Simulator hiện gửi `temperature` và `rpm`, không gửi `vibration` bắt buộc của schema motor mẫu; nhiệt độ mẫu cũng chỉ 20–45 °C. Industrial acceptance cần payload conformant cùng chế độ deterministic để kiểm tra trigger/recovery, không dùng random demo hiện tại làm bằng chứng.
- Giữ MQTT topic contract hiện hành: `liteboard/{project_id}/devices/{device_id}/telemetry`. Topic thân thiện như `factory/motor-01/telemetry` chỉ được dùng nếu có thay đổi contract và ACL được đặc tả riêng.
- Tách trạng thái kết nối Device khỏi chất lượng dữ liệu: Device có thể online nhưng telemetry bị schema reject; latest valid telemetry không bị ghi đè bởi sample trễ.
- PostgreSQL là điểm bắt đầu cho dữ liệu bền vững. Chỉ thêm TimescaleDB/partitioning khi benchmark chứng minh nhu cầu.
- LiteBoard phát alert/event về trạng thái thiết bị. EAMO sở hữu work order, phân công kỹ thuật viên và lịch sử bảo trì.

## Các feature phase

| Track | Feature | Kết quả chính | Plan tổng thể liên quan | Trạng thái |
|---|---|---|---|---|
| IT-1 | Project, Equipment và Device binding | Dùng Tenant/Project/Asset/Device/Access Token hiện có; demo association MOTOR-01 có authorization đúng scope. | Plan Phase 1 | Implementation hiện có; industrial acceptance còn mở. |
| IT-2 | Device Profile và Telemetry Schema | Project-owned Profile, schema versioned, Device assignment và reason code validation. | Plan Phase 3 (schema gate) | CRUD/assignment/validator đã có; durable outcome và end-to-end acceptance còn mở. |
| IT-3 | MQTT ingest và lưu bền vững | Envelope bắt buộc, resolve active Tenant/Project/Device, PostgreSQL commit, dedup và restart/retry. | Plan Phase 2–3 | Migration, service flow, unit tests và gated PG/Mosquitto integration tests đã có; live integration và L0 reconciliation còn mở. |
| IT-4 | REST latest/history, WebSocket và trace | Authorized query; latest không lùi do late sample; reconnect/recovery và trace có stage timestamps. | REST query chuyển từ Plan Phase 3; realtime/trace ở Plan Phase 4 | Chưa implement. |
| IT-5 | Rule, alert và outbound event | Duration/gap/recovery semantics, alert lifecycle, transactional outbox, retry và delivery history. | Plan Phase 5 | Chưa implement. |
| Commands/RPC | Device commands và ACK | Persistent command lifecycle, expiry, retry và idempotent Device ACK. | Plan Phase 6 | Topic/ACL hiện có; command API, state machine và ACK processing chưa implement. |
| IT-6 | EAMO event contract | Versioned alert lifecycle event, tenant-owned mapping, outbox/inbox dedup và reconciliation. | Plan Phase 8 — EAMO/MES | Chưa implement; phụ thuộc IT-5. |

Các mã `IT-*` chỉ thuộc roadmap này, tránh nhầm với số Phase của roadmap chính và roadmap hardening Device.

## Demo acceptance cuối vertical slice

1. Tạo `factory-demo` trong một Tenant; tạo Factory, Production Line và Equipment `MOTOR-01` trong Project.
2. Tạo Device `motor-01`, liên kết tới `MOTOR-01`, gán `motor-v1` và lấy Access Token theo flow hiện hành.
3. Device publish message có `message_id`, `schema_version`, `occurred_at`, `values` qua MQTT TLS; telemetry hợp lệ được ghi một lần, bản tin trùng không tạo sample thứ hai.
4. Cùng `(device_id, message_id)` và cùng nội dung là duplicate; cùng ID nhưng khác nội dung bị phát hiện conflict. Profile Version ID và Tenant/Project scope được giữ cùng sample.
5. Người có quyền trong Project đọc latest/history qua REST và nhận authorized WebSocket updates; late sample không lùi latest; unauthorized scope bị từ chối.
6. Nhiệt độ vượt ngưỡng đủ duration theo max-gap rule tạo một alert `CRITICAL`; duplicate/replay không nhân đôi alert; acknowledge không đồng nghĩa clear; recovery clear theo contract.
7. EAMO nhận event detected/recovered versioned có Tenant/Project, mapping Equipment, Device, Alert, rule revision, source sample và event ID ổn định qua retry.
8. Receiver duplicate, outage/recovery và version không hỗ trợ có kết quả kiểm thử; outbox age, retry và reconciliation được quan sát.
9. Báo cáo sent/persisted/rejected/duplicate/conflict/failed, failure được thử, recovery và p50/p95/p99 đã đo. Không ghi số latency minh họa thành cam kết trước khi benchmark.

## Giới hạn của roadmap

- Không thay Access Token hoặc topic ACL bằng ID thân thiện do client gửi.
- Không coi MQTT publisher PUBACK là bằng chứng telemetry đã commit vào PostgreSQL; ACK semantics cần được kiểm chứng với client/broker trước khi đóng IT-3.
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
