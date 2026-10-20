# Telemetry công nghiệp

Đường đi của telemetry từ thiết bị đến cảnh báo: nhận qua MQTT, kiểm tra theo Profile, lưu trữ, trạng thái hiện diện, REST và WebSocket, trace, rule và alert, webhook. Laravel sở hữu cấu hình, tenant/project scope, authorization và REST; telemetry-service (Python) nhận message, ghi PostgreSQL, đánh giá rule và phát event sau khi lưu.

## Ranh giới

- Xác thực và cấp Access Token cho thiết bị thuộc [devices](../devices/README.md); tài liệu ở đây bắt đầu từ lúc message đã đến broker.
- Cô lập Tenant/Project và Asset thuộc [tenant](../tenant/README.md); xác thực giữa backend và telemetry-service thuộc [auth](../auth/README.md).
- ML IDS cho lưu lượng MQTT là hướng nghiên cứu riêng, xem [mqtt-ids](../mqtt-ids/README.md).
- Workflow bảo trì (work order, phân công, lịch sử bảo trì) thuộc EAMO, không thuộc LiteBoard.

## Hiện trạng

IT-1 đến IT-5 đã được gom vào một tài liệu. Mô tả phản ánh code trong repository và các kết quả kiểm thử local đã ghi nhận (đối chiếu 2026-10-18); không đồng nghĩa với nghiệm thu pilot hay production.

| Tài liệu | Nội dung |
| --- | --- |
| [Telemetry service, Control Plane và frontend](./current/telemetry-flow.md) | Ownership/tenant scope, stage/outcome, WebSocket close code, cấu hình, giới hạn delivery, test và L0, payload MQTT, ingest transaction, profile validation và profile-less persistence, PostgreSQL, presence, REST, WebSocket, trace, rule/alert/outbox/webhook, UI, giới hạn đã biết |

## Kế hoạch

| Phase | Phạm vi | Trạng thái | Tài liệu |
| --- | --- | --- | --- |
| Phase 6 | Giao alert event cho EAMO qua hàng đợi (Redis Streams, outbox relay, consumer contract, DLQ) | Kế hoạch. Outbox và webhook HTTP đã có từ IT-5 | [Phase 6](./plan/phase-06-eamo-integration.md) |

## Tài liệu nền

- [Kế hoạch tổng thể LiteBoard](../../plan.md)
- [Tenant, Project và Asset contract](../tenant/current/tenant-project-asset.md)
- [Device Access Token và provisioning](../devices/current/devices_access_token.md)
- [Hardening Access Token, TLS và ingestion](../devices/plan/future_device_security_and_ingestion_plan.md)
