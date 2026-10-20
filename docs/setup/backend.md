# Backend Setup (Laravel)

The backend lives in `backend/` and listens on port `8800`. It is the control plane: login, authorization, tenants and projects, Devices, Access Tokens, and the business APIs. It also owns the PostgreSQL schema that Mosquitto and the telemetry service rely on.

## Run with Docker

This is part of [running everything with Docker](docker.md#run-everything-with-docker). To start only the backend and its dependencies:

```powershell
docker compose --profile application up -d --build postgres redis backend
docker compose exec backend php artisan db:seed
```

The container runs `php artisan migrate --force` on every start, then `php artisan serve`. The `webhook-dispatcher` service (same image) runs `php artisan webhooks:dispatch` to deliver webhooks. It needs the same `APP_KEY` as the backend because endpoint secrets are encrypted with that key.

## Run directly on the host

Requirements: PHP 8.4 (`pdo_pgsql` extension), Composer 2, PostgreSQL 16, and Redis (a container works).

```powershell
docker compose --profile application up -d postgres redis

cd backend
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve --host=0.0.0.0 --port=8800
```

In `backend/.env`, set these to match the root `.env`:

| Variable | Value |
|---|---|
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, and `POSTGRES_PORT` from the root `.env` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Same as `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` |
| `TELEMETRY_WS_SECRET` | Same value as the telemetry service, at least 32 characters |
| `DEVICE_PROVISIONING_MQTT_HOST` | Broker hostname, `mqtt.local` by default |

The template sets `CACHE_STORE=file`, while Docker uses `redis`. To match Docker, set `CACHE_STORE=redis` and `REDIS_HOST=127.0.0.1`.

## Verify

```powershell
curl http://localhost:8800/api/health
```

Sign in to the dashboard with `admin@gmail.com` / `12345678` (created by `CoreDatabaseSeeder`, for local use only).

## Quality checks

Run from `backend/`, in this order:

```powershell
composer stan
composer test
composer pint
```

## Troubleshooting

| Symptom | Fix |
|---|---|
| `could not find driver` | Enable the `pdo_pgsql` extension in `php.ini`. |
| Cannot connect to the database | Check `DB_HOST`/`DB_PORT` and `POSTGRES_PORT`; use `127.0.0.1` from the host and `postgres` from a container. |
| Mosquitto logs `relation "v_mqtt_users" does not exist` | `php artisan migrate` has not run, or Mosquitto points at a different database than Laravel. |
| Telemetry WebSocket closes with code `1011` | `TELEMETRY_WS_SECRET` is unset or shorter than 32 characters. |
