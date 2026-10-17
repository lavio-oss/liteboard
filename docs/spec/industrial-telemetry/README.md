# Telemetry công nghiệp

> IT-1 đến IT-4 đã được gom vào tài liệu flow hiện hành. Mô tả dưới đây phản ánh code trong repository và các kết quả kiểm thử local đã ghi nhận; không đồng nghĩa với nghiệm thu pilot/production.

## Flow hiện tại

- [Telemetry service, Control Plane và frontend](./telemetry-flow-current.md): ownership/tenant scope, MQTT payload, ingest transaction, profile validation và profile-less persistence, PostgreSQL, presence, REST, WebSocket, trace, UI và giới hạn đã biết.

## Roadmap tiếp theo

- [IT-5 — Rule, alert và webhook](./phase-05-rules-alerts-webhook.md)
- [IT-6 — Event contract với EAMO](./phase-06-eamo-integration.md)

## Tài liệu nền

- [Kế hoạch tổng thể LiteBoard](../../plan.md)
- [Tenant, Project và Asset contract](../tenant/tenant-project-asset.md)
- [Device Access Token và provisioning](../devices/devices_access_token.md)
- [Hardening Access Token, TLS và ingestion](../devices/access_token_dev_plan/future_device_security_and_ingestion_plan.md)
