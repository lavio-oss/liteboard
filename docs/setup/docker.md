# LiteBoard Setup Guide

How to run each LiteBoard component. For the architecture overview and port map, see [docs/README.md](../README.md).

| Component | Guide | Stack |
|---|---|---|
| Backend | [backend.md](backend.md) | Laravel 13, PostgreSQL |
| Web | [web.md](web.md) | Nuxt 4, pnpm |
| Telemetry service | [telemetry-service.md](telemetry-service.md) | FastAPI, gmqtt |
| Mosquitto | [mosquitto.md](mosquitto.md) | `mosquitto-go-auth`, TLS `8883` |

## Run everything with Docker

Requirements: Docker Desktop (Compose v2) and OpenSSL 1.1.1+. PHP, Composer, and Node are not needed.

Run these from the repository root, in order:

```powershell
# 1. Create .env (keep an existing one; only add missing variables)
if (-not (Test-Path .env)) { Copy-Item .env.example .env }

# 2. Generate the CA and TLS certificates for Mosquitto (run once)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infrastructure\mosquitto\generate-dev-certs.ps1

# 3. Generate APP_KEY and copy the output into APP_KEY in .env
docker compose run --build --rm --no-deps backend php artisan key:generate --show
```

Before starting, open `.env` and set:

| Variable | Value |
|---|---|
| `APP_KEY` | Output of step 3 |
| `POSTGRES_PASSWORD` | Your own password (replace `change-me`) |
| `MQTT_INTERNAL_PASSWORD` | Your own password, see [mosquitto.md](mosquitto.md#internal-passwords) |
| `MQTT_HEALTHCHECK_PASSWORD` | Your own password |
| `TELEMETRY_WS_SECRET` | Random string of at least 32 characters; the backend and telemetry service must share it |

```powershell
# 4. Start the stack
docker compose --profile application up -d --build
docker compose ps

# 5. Create the admin account and demo data (local environments only)
docker compose exec backend php artisan db:seed
```

The backend runs migrations when its container starts. Step 5 creates the account `admin@gmail.com` / `12345678`; change that password if the host is reachable from outside your machine.

| Service | Address |
|---|---|
| Dashboard | http://localhost:3000 |
| Laravel API | http://localhost:8800/api/health |
| Telemetry service | http://localhost:8100/health |
| MQTT (TLS) | `mqtt.local:8883` |

View logs with `docker compose logs -f backend mosquitto telemetry-service frontend`.

## Root environment variables

The root `.env` is for Docker Compose only. The template is [.env.example](../../.env.example).

| Variable | Meaning |
|---|---|
| `POSTGRES_PORT`, `REDIS_PORT`, `BACKEND_PORT`, `TELEMETRY_PORT`, `FRONTEND_PORT` | Ports published on the host. Change one when it is already taken, for example `POSTGRES_PORT=5433`. |
| `MQTT_PG_HOST`, `MQTT_PG_PORT` | Where Mosquitto and the telemetry service find PostgreSQL. For PostgreSQL in Compose: `postgres` and `5432`. |
| `MQTT_REDIS_HOST`, `MQTT_REDIS_PORT` | Redis used by Mosquitto for its auth cache. |
| `MQTT_TLS_HOSTNAME` | Broker hostname; it must appear in the certificate SAN. |

The secrets are not interchangeable: `APP_KEY` (Laravel encryption), `POSTGRES_PASSWORD` (database), `MQTT_INTERNAL_PASSWORD` (telemetry service login to the broker), `TELEMETRY_WS_SECRET` (signs WebSocket tickets), and the Device Access Token (one device's credential). Never commit `.env`.

## Run a single component on the host

When developing one component, start only the infrastructure in Docker and run that component directly:

```powershell
docker compose --profile application up -d postgres redis mosquitto
```

Do not run a component in Docker and on the host at the same time; they compete for the port. Stop the container first, for example `docker compose stop backend`.
