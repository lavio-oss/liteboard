# Web Setup (Nuxt Dashboard)

The web app lives in `web/` and listens on port `3000`. The dashboard only calls the Laravel API and the telemetry service WebSocket; it never touches the database or MQTT directly.

## Run with Docker

```powershell
docker compose --profile application up -d --build frontend
```

`frontend` depends on `backend`, so Compose also starts the backend, PostgreSQL, and Redis. The container reaches the API through `NUXT_LARAVEL_API_BASE=http://backend:8800`; the browser connects to the WebSocket at `ws://localhost:8100` (per `TELEMETRY_PORT`).

## Run directly on the host

Requirements: Node.js and pnpm. The backend must already be running at `http://localhost:8800` ([backend.md](backend.md)).

```powershell
cd web
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
pnpm install
pnpm dev
```

Open http://localhost:3000.

## Configuration

The backend and telemetry addresses live in `runtimeConfig` in `web/nuxt.config.ts`. Override them with environment variables (in `web/.env` or the shell):

| Variable | Default | Meaning |
|---|---|---|
| `NUXT_LARAVEL_API_BASE` | `http://localhost:8800` | Laravel API address |
| `NUXT_PUBLIC_TELEMETRY_WS_BASE` | `ws://localhost:8100` | Telemetry service WebSocket address |
| `NUXT_PUBLIC_SITE_URL` | empty | Public URL, used by `nuxt generate` |

The backend allows the origin `http://localhost:3000` (`CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`). If you run the web app on another port, change both variables in the backend.

## Quality checks

Run from `web/`:

```powershell
pnpm typecheck
pnpm lint
pnpm test
```

## Troubleshooting

| Symptom | Fix |
|---|---|
| Login fails with a network or CORS error | The backend is not running, or the web origin is missing from `CORS_ALLOWED_ORIGINS`. |
| Realtime charts do not update | The telemetry service is not running, or `NUXT_PUBLIC_TELEMETRY_WS_BASE` has the wrong port. |
| Port `3000` is taken | Change `FRONTEND_PORT` (Docker), or run `pnpm dev --port 3001` and update CORS in the backend. |
