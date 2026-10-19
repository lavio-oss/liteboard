# Telemetry Service Setup

`telemetry-service/` is a FastAPI MQTT worker listening on port `8100`. It subscribes to telemetry and heartbeat topics on Mosquitto, writes samples, latest values, presence, and alerts to PostgreSQL, and pushes realtime events over WebSocket. Processing details are in [telemetry-service/README.md](../../telemetry-service/README.md).

The service needs three things running first:

1. PostgreSQL, migrated by the backend. The service writes to tables Laravel creates; against an older schema every ingest fails and the broker keeps redelivering.
2. Mosquitto ([mosquitto.md](mosquitto.md)).
3. The internal MQTT account (`MQTT_INTERNAL_USERNAME` / `MQTT_INTERNAL_PASSWORD`).

## Run with Docker

```powershell
docker compose --profile application up -d --build postgres backend telemetry-service
docker compose ps telemetry-service
```

Compose passes the MQTT, database, and `TELEMETRY_WS_SECRET` settings from the root `.env`. Run exactly one replica per `MQTT_CLIENT_ID`.

## Run directly on the host

Requirements: Python 3.12. PostgreSQL (migrated) and Mosquitto run in Docker.

```powershell
docker compose --profile application up -d postgres redis mosquitto
docker compose --profile application up -d backend   # runs migrations; may be stopped afterwards

cd telemetry-service
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
```

Set the environment variables, then start the service:

```powershell
$env:MQTT_HOST = 'mqtt.local'
$env:MQTT_PORT = '8883'
$env:MQTT_TLS_CA_FILE = (Resolve-Path ..\infrastructure\mosquitto\certs\ca.crt).Path
$env:MQTT_USERNAME = 'telemetry-service'
$env:MQTT_PASSWORD = '<MQTT_INTERNAL_PASSWORD>'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '5432'
$env:DB_DATABASE = 'liteboard'
$env:DB_USERNAME = '<POSTGRES_USER>'
$env:DB_PASSWORD = '<POSTGRES_PASSWORD>'
$env:TELEMETRY_WS_SECRET = '<same value as the backend, at least 32 characters>'
uvicorn app.main:app --host 0.0.0.0 --port 8100
```

`mqtt.local` only resolves inside the Docker network. On the host, add `127.0.0.1 mqtt.local` to `C:\Windows\System32\drivers\etc\hosts` (requires Administrator) so the hostname matches the certificate SAN. Do not disable hostname verification.

## Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `MQTT_HOST`, `MQTT_PORT` | `localhost`, `8883` | Broker TLS address |
| `MQTT_TLS_CA_FILE` | `/run/secrets/mqtt_tls_ca_certificate` | CA used to verify the broker |
| `MQTT_USERNAME`, `MQTT_PASSWORD` | empty | Internal broker account |
| `MQTT_CLIENT_ID` | `liteboard-telemetry-service` | Stable ID for the persistent session |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `localhost`, `5432`, `liteboard`, `liteboard`, `liteboard` | PostgreSQL |
| `TELEMETRY_WS_SECRET` | empty | Signs WebSocket tickets; must equal the backend value |
| `TELEMETRY_WS_MAX_CONNECTIONS` | `500` | WebSocket connection cap |

The full list is in [telemetry-service/README.md](../../telemetry-service/README.md#configuration).

## Verify

```powershell
curl http://localhost:8100/health   # process is alive
curl http://localhost:8100/ready    # PostgreSQL and the broker are reachable
```

Run the unit tests from `telemetry-service/`:

```powershell
python -m unittest discover -s tests -p "test_*.py"
```

## Troubleshooting

| Symptom | Fix |
|---|---|
| `/ready` reports an MQTT error | Mosquitto is not healthy, the internal password is wrong, or `MQTT_HOST` does not match the SAN. |
| The broker redelivers every message | The schema is not migrated. Run `php artisan migrate`, then restart the service. |
| WebSocket closes with code `1011` | `TELEMETRY_WS_SECRET` is unset or shorter than 32 characters. |
| Still failing after changing the MQTT password | Recreate both: `docker compose up -d --force-recreate mosquitto telemetry-service`. |
