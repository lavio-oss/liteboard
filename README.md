# LiteBoard

**LiteBoard** is a developer-first IoT / IIoT (Industrial Internet of Things) control plane platform designed for MQTT-connected devices, industrial automation, and smart factory telemetry.

It provides a multi-tenant hierarchy (`Tenants` -> `Projects` -> `Factories` -> `Production Lines`), device management, telemetry streaming, and an interactive flow dashboard.

---

## Architecture & Project Structure

The repository is organized as a monorepo containing the modular backend, modern web dashboard, telemetry ingestion pipeline, and edge simulator:

```text
LiteBoardv2/
├── backend/               # Laravel 11/12 Control-Plane API (Modular Monolith)
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
├── telemetry-service/     # FastAPI / Python telemetry ingestion service
│   ├── app/               # Ingestion logic consuming MQTT telemetry
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

For the complete architectural design, technology stack, implementation details, and development roadmap of the project, refer to:

- **[docs/plan.md](docs/plan.md)** — Master Project Plan & System Specifications.

---

## Getting Started

### 1. Running with Docker Compose (Recommended)

Start the complete stack (PostgreSQL, Redis, Mosquitto, Backend API, Web App, Telemetry Service):

```powershell
# Copy environment configuration
Copy-Item .env.example .env

# Build and start all services
docker compose up -d --build
```

#### Service Endpoints & Health Checks:
- **Web Dashboard**: [http://localhost:3000](http://localhost:3000)
- **Backend API**: [http://localhost:8081/api/health](http://localhost:8081/api/health)
- **Telemetry Service**: [http://localhost:8100/health](http://localhost:8100/health)
- **MQTT Broker**: `mqtt://localhost:1883` (WebSocket: `ws://localhost:9001`)

#### Run the IoT Device Simulator:
```powershell
docker compose --profile tools run --rm simulator
```

---

### 2. Running Locally for Development

For active development, run the backend and web frontend in separate terminals:

#### Backend (Laravel)
```powershell
cd backend
composer install
php artisan migrate
php artisan serve --port=8081
```
API runs at `http://localhost:8081`.

#### Frontend (Nuxt 4)
```powershell
cd web
pnpm install
pnpm dev
```
Dashboard runs at `http://localhost:3000`.

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
