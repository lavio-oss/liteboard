# LiteBoard

**LiteBoard** is a developer-first IoT / IIoT (Industrial Internet of Things) control plane platform designed for MQTT-connected devices, industrial automation, and smart factory telemetry.

It provides a multi-tenant hierarchy (`Tenants` -> `Projects` -> `Factories` -> `Production Lines`), device management, telemetry streaming, and an interactive flow dashboard.

---

## Architecture & Project Structure

The repository is organized as a monorepo containing the modular backend, modern web dashboard, telemetry ingestion pipeline, and edge simulator:

```text
LiteBoardv2/
├── backend/               # Laravel 13 Control-Plane API (Modular Monolith)
│   ├── app/               # Host-level providers & infrastructure tooling
│   ├── database/          # Forward-only database migrations & seeders (PostgreSQL)
│   ├── modules/           # Domain business modules
│   │   ├── Core/          # Auth, Sanctum tokens, Tenancy, User management
│   │   └── Masterdata/    # Projects, Factories, Production Lines CRUD & business logic
│   └── tests/             # Automated test suite (Pest & PHPStan)
│
├── web/                   # Frontend Dashboard (Nuxt 4, Vue 3, Nuxt UI v4)
│   ├── app/
│   │   ├── components/    # Vue components (TenantHierarchyFlow, Modals, Tables)
│   │   ├── composables/   # Reusable state & utilities (useApi, useAuth, useDateTime)
│   │   ├── pages/         # Nuxt file-based routing (Masterdata, Tenants, Settings)
│   │   └── types/         # Zod schemas and TypeScript data contracts
│   └── package.json       # Frontend dependencies (Nuxt, VueFlow, Tailwind v4)
│
├── telemetry-service/     # FastAPI / Python MQTT subscriber and presence service
│   ├── app/               # Validates messages and updates Device presence
│   └── Dockerfile
│
├── simulator/             # IoT device simulator for testing telemetry streams
│   ├── app/               # MQTT metric generation scripts
│   └── Dockerfile
│
├── infrastructure/        # Infrastructure configs (Eclipse Mosquitto MQTT broker)
│   └── mosquitto/
│
├── docs/                  # Project documentation & architectural plans
│   └── plan.md            # Master project roadmap and system architecture specifications
│
├── AGENTS.md              # Repository guidelines, rules, and testing standards
├── docker-compose.yml     # Multi-service local deployment orchestrator
└── README.md              # Project entry point
```

---

## Core System Principles

1. **Multi-Tenancy**: Tenant-owned data is isolated across models, queries, authorization policies, cache, and UI views.
2. **Strict Data Contract Integrity**: Single source of truth across all layers with no ad-hoc coercions, multi-source fallbacks (`??`), or shadow fields.
3. **UTC+0 Storage Invariant**: Database columns and backend APIs strictly use UTC (`UTC+0`). Localized date/time display is handled exclusively by client-side composables.
4. **Forward-Only Migrations**: Database schemas evolve forward; `down()` methods are disallowed.
5. **Quality Gates**: Every change must satisfy PHPStan (level 8/max), Pest tests, Pint formatting, Nuxt typechecking, and ESLint.

---

## Documentation

Start with the project map and local setup guide, then use the detailed references:

- **[docs/README.md](docs/README.md)** — Architecture, environment keys, ports, and local setup.
- **[docs/plan.md](docs/plan.md)** — Master Project Plan & System Specifications.
- **[infrastructure/mosquitto/README.md](infrastructure/mosquitto/README.md)** — Mosquitto MQTT Broker (`mosquitto-go-auth`) Setup, Authentication & Operations Guide.

---

## Getting Started

Use the step-by-step [local setup guide](docs/README.md). It covers the Laravel key, MQTT TLS certificates, environment files, ports, Docker startup, simulator, and the optional host-local workflow.

---
## Quality Checks & Testing

Before committing code, ensure all quality gates pass:

### Backend Quality Gates
```powershell
cd backend

# Static Analysis
composer stan

# Automated Tests
php artisan test --compact

# Code Styling Formatter
composer pint
```

### Frontend Quality Gates
```powershell
cd web

# TypeScript Type Checking
pnpm typecheck

# Code Linter
pnpm lint
```

---

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE) for details.
