# Mosquitto Setup

The broker uses the `mosquitto-go-auth` image. Devices connect over **MQTT TLS on port `8883`**; there is no `1883` listener and no WebSocket `9001`. The broker authenticates Access Tokens and enforces per-topic ACLs through the `v_mqtt_users` and `v_mqtt_acls` views in PostgreSQL, with Redis as a cache (5 second TTL). Configuration lives in [infrastructure/mosquitto/](../../infrastructure/mosquitto/) (`mosquitto.conf`, `acls`, `generate-dev-certs.ps1`, and the Git-ignored `certs/`). Passwords for the internal accounts are never stored in Git: they are bcrypt-hashed from `.env` into `/tmp/mosquitto-passwords` on every container start.

## Authentication and authorization

The broker runs with `allow_anonymous false` and evaluates two backends in order:

1. **`files`** (internal services, see `acls`): `telemetry-service` gets `readwrite` and `subscribe` on `liteboard/#`; `healthcheck` may only write to the `healthcheck` topic.
2. **`postgres`** (Devices): the Device connects with `username = password = <ACCESS_TOKEN>`. Mosquitto hashes the username with SHA-256 and queries two views:
   - `v_mqtt_users` requires the Device, Project, Tenant and credential to be active and `approved`, and checks the password against `mqtt_password_hash` (bcrypt).
   - `v_mqtt_acls` isolates topics per Device. Publish: `liteboard/{project_id}/devices/{device_id}/telemetry`, `.../heartbeat`, `.../commands/ack`. Subscribe: `.../commands`.

Results are cached in Redis for 5 seconds, so a revoked or rotated credential loses access within 5 seconds. Clients verify the server certificate and hostname; client certificates (mTLS) are not required.

The broker needs:

1. PostgreSQL, migrated by the backend so the auth views exist ([backend.md](backend.md)).
2. Redis.
3. TLS certificates and two internal passwords.

## 1. Generate TLS certificates

Requires OpenSSL 1.1.1+. Run once from the repository root:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infrastructure\mosquitto\generate-dev-certs.ps1
```

The script creates a CA, the server certificate, and a CA header for the ESP32 sketch. The default SAN covers `mqtt.local`, `mosquitto`, `localhost`, and the sample IP `192.168.1.100`. If devices connect with another name or IP, add it on the first run:

```powershell
.\infrastructure\mosquitto\generate-dev-certs.ps1 -IpAddress @('127.0.0.1','192.168.1.50') -DnsName @('mqtt.example.lan')
```

The script refuses to overwrite existing certificates. The CA and broker private keys stay on this machine and never go into Git or firmware; clients only need the public CA. Do not use the development CA in production.

## 2. Configure `.env`

Mosquitto reads its variables from the root `.env`. Create it if it does not exist:

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

### Internal passwords

`MQTT_INTERNAL_PASSWORD` is the password the telemetry service uses to log in to the broker, and `MQTT_HEALTHCHECK_PASSWORD` is used by the healthcheck. Neither is a Device Access Token. Generate each value once:

```powershell
$bytes = New-Object byte[] 32
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($bytes)
[Convert]::ToBase64String($bytes)
$rng.Dispose()
```

```dotenv
MQTT_INTERNAL_USERNAME=telemetry-service
MQTT_INTERNAL_PASSWORD=<generated value>
MQTT_HEALTHCHECK_USERNAME=healthcheck
MQTT_HEALTHCHECK_PASSWORD=<a different generated value>
```

Mosquitto bcrypt-hashes both passwords on every start. Do not use the Compose fallback passwords (`telemetry-service-secret`, `healthcheck-secret`) outside your local machine.

### Database and TLS

| Case | `MQTT_PG_HOST` | Notes |
|---|---|---|
| PostgreSQL in Compose | `postgres` | Default in `.env.example`; `MQTT_PG_PORT=5432` |
| PostgreSQL installed on the host | `host.docker.internal` | `POSTGRES_USER` and `POSTGRES_PASSWORD` must match the database Laravel uses |

```dotenv
MQTT_TLS_HOSTNAME=mqtt.local
MQTT_TLS_CERT_FILE=./infrastructure/mosquitto/certs/broker/server.crt
MQTT_TLS_KEY_FILE=./infrastructure/mosquitto/certs/broker/server.key
MQTT_TLS_CA_SOURCE=./infrastructure/mosquitto/certs/ca.crt
```

`MQTT_TLS_HOSTNAME` must appear in the certificate SAN. `POSTGRES_PORT` is only the port published on the host; `MQTT_PG_PORT` is the port the broker actually uses to reach the database.

## 3. Start the broker

```powershell
docker compose --profile application up -d postgres redis
docker compose --profile application up -d --build backend   # migrates, creating the auth views
docker compose up -d redis mosquitto
docker compose ps mosquitto
docker compose logs --tail=30 mosquitto
```

The broker is ready when `docker compose ps` reports `healthy` and the log contains:

```text
backend registered: Postgres
redisCache activated
```

After changing `.env`, recreate the containers to load the new values: `docker compose up -d --force-recreate mosquitto telemetry-service`.

## 4. Verify

An anonymous client must be refused:

```powershell
docker compose exec -T mosquitto mosquitto_pub --cafile /run/secrets/mqtt_tls_ca_certificate -h mqtt.local -p 8883 -t test -m "hello"
# Connection Refused: not authorised.
```

Publish with the Access Token of an approved Device (the token is used for both `-u` and `-P`):

```powershell
docker compose exec -T mosquitto mosquitto_pub `
  --cafile /run/secrets/mqtt_tls_ca_certificate -h mqtt.local -p 8883 -q 1 `
  -u "<ACCESS_TOKEN>" -P "<ACCESS_TOKEN>" `
  -t "liteboard/<PROJECT_ID>/devices/<DEVICE_ID>/telemetry" `
  -m '{"temperature": 26.5}'
```

Watch every topic with the internal account:

```powershell
docker compose exec -T mosquitto mosquitto_sub `
  --cafile /run/secrets/mqtt_tls_ca_certificate -h mqtt.local -p 8883 `
  -u "telemetry-service" -P "<MQTT_INTERNAL_PASSWORD>" `
  -t "liteboard/#" -v
```

## Connecting from outside Docker

`mqtt.local` is only an alias inside the Docker network. A device or the host needs DNS or a hosts entry pointing that name at the machine running the broker, or it must connect by an IP that is in the SAN. Clients must trust the broker CA and verify the hostname. A PLC without MQTT TLS support has to go through a gateway.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `relation "v_mqtt_users" does not exist` | `php artisan migrate` has not run, or `MQTT_PG_HOST` points at an empty PostgreSQL volume instead of the Laravel database. Fix it, then `--force-recreate mosquitto`. |
| Cannot read the certificate or key | Run the certificate script, or point the `MQTT_TLS_*` variables at readable files. |
| Hostname verification fails | Add the name or IP you connect with to the SAN and set `MQTT_TLS_HOSTNAME` to match. Never disable verification. |
| `.env` changes have no effect | `docker compose up -d --force-recreate mosquitto`. |
