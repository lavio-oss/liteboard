# Tiến độ Access Token

## Trạng thái

CRUD Device bằng Access Token, quản lý credential, broker auth/ACL, telemetry và web UI đã có implementation trong Phase 1–5. Cấu hình MQTT TLS server-auth đã có; broker runtime và kiểm tra TLS end-to-end vẫn còn mở. HTTPS Device bootstrap đang là kế hoạch, chưa triển khai.

## Danh sách Phase

| Phase | Phạm vi | Trạng thái |
|---|---|---|
| [Phase 1](./phase-1-database-and-sql-views.md) | Device schema và PostgreSQL MQTT views | Hoàn thành theo checklist |
| [Phase 2](./phase-2-laravel-control-plane.md) | Laravel Device API và vòng đời credential | Hoàn thành theo checklist |
| [Phase 3](./phase-3-mqtt-broker-mosquitto-go-auth.md) | Mosquitto Access Token auth và Device ACL | Đã có implementation; cần xác minh lại runtime broker |
| [Phase 4](./phase-4-telemetry-data-plane-and-edge.md) | Telemetry service, simulator và ESP32 | Luồng chính đã có; TLS broker end-to-end còn mở |
| [Phase 5](./phase-5-nuxt-web-ui.md) | UI Device và credential | Hoàn thành theo checklist |
| [Phase 6](./phase-6-https-access-token-provisioning.md) | HTTPS bootstrap theo Project, trả Access Token | Kế hoạch; chưa triển khai |

## Ngoài luồng này

- MQTT Basic và strict client-ID binding: [tiến độ MQTT Basic](../mqtt_basic_progress/).
- X.509/mTLS, quota tài nguyên và telemetry schema/replay protection: [kế hoạch tương lai](../future_plans/).
- Số Phase ở đây chỉ thuộc roadmap Access Token, không đánh số cho luồng MQTT Basic riêng.
