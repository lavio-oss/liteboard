# LiteBoard

**LiteBoard** is a developer-first IoT / IIoT control plane for MQTT-connected devices, industrial automation and smart factory telemetry: multi-tenant hierarchy (`Tenants` → `Projects` → `Factories` → `Production Lines`), device management, telemetry streaming and an interactive flow dashboard.

**Overview**: devices online, alerts needing action, rejection reasons, webhook delivery.

[![LiteBoard overview](docs/img/overview.png)](docs/img/overview.png)

**Workflow**: device groups mapped to device profile versions.

[![LiteBoard workflow](docs/img/workflow.png)](docs/img/workflow.png)

**Schema**: versioned telemetry schema per device profile.

[![LiteBoard telemetry schema](docs/img/schema.png)](docs/img/schema.png)

Stack: Laravel 13 API (`backend/`), Nuxt 4 dashboard (`web/`), FastAPI telemetry ingestion (`telemetry-service/`), Mosquitto MQTT broker (`infrastructure/`), device simulator (`simulator/`). Architecture and docs index: [docs/architecture.md](docs/architecture.md).

## Setup

| Machine | Guide |
|---|---|
| Windows (Docker Desktop) | [docs/setup/docker.md](docs/setup/docker.md) |
| Linux / WSL2 (Debian, Docker Engine) | [docs/setup/linux.md](docs/setup/linux.md) |
| Production server (Debian VPS, nginx) | [docs/setup/production.md](docs/setup/production.md) |
