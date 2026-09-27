# LiteBoard MQTT Broker (`mosquitto-go-auth`)

This directory contains the configuration for the **Eclipse Mosquitto MQTT Broker** integrated with the **[`iegomez/mosquitto-go-auth`](https://github.com/iegomez/mosquitto-go-auth)** plugin. It provides **ThingsBoard-style Access Token authentication** for IoT devices and topic-level authorization (ACL) directly backed by PostgreSQL and cached via Redis.

---

## 1. Directory Structure

```text
infrastructure/mosquitto/
├── mosquitto.conf    # Template configuration (uses __MQTT_*__ placeholders, zero hardcoded secrets)
├── acls              # Static Access Control List (ACL) for internal system accounts
└── README.md         # Setup and operations guide
```

> **Security Note**: This directory **does not** store a static `passwords` file in Git. Passwords for internal accounts (`telemetry-service`, `healthcheck`) are automatically hashed using `/mosquitto/pw` (Bcrypt) from environment variables in `.env` into `/tmp/mosquitto-passwords` when the container starts up.

---

## 2. Authentication & Authorization Architecture (`files` + `postgres`)

Mosquitto runs with `allow_anonymous false` (disallowing all anonymous connections) and evaluates two sequential backends:

1. **`files` Backend (Internal System Services)**:
   - **`telemetry-service`**: Used by the Python Data Plane service (`telemetry-service`). Granted `readwrite` and `subscribe` permissions across `liteboard/#` (configured in [`acls`](./acls)).
   - **`healthcheck`**: Used exclusively by Docker container healthcheck probes. Restricted to `write` only on the `healthcheck` topic.
2. **`postgres` Backend (Client IoT Devices)**:
   - Devices connect using `username = <ACCESS_TOKEN>` and `password = <ACCESS_TOKEN>`.
   - Mosquitto hashes `username` via SHA-256 and queries two PostgreSQL Views:
     - **`v_mqtt_users`**: Verifies that device, project, tenant, and credential are all in `active` status, and matches the password against `mqtt_password_hash` (`bcrypt $2a$`).
     - **`v_mqtt_acls`**: Enforces strict per-device topic isolation:
       - Publish (`rw = 2`):
         - `liteboard/{project_id}/devices/{device_id}/telemetry`
         - `liteboard/{project_id}/devices/{device_id}/heartbeat`
         - `liteboard/{project_id}/devices/{device_id}/commands/ack`
       - Subscribe & Read (`rw = 4`, `rw = 1`):
         - `liteboard/{project_id}/devices/{device_id}/commands`
3. **Redis Cache (`5 seconds`)**:
   - Authentication and ACL evaluation results are cached for 5 seconds in Redis to handle high-frequency telemetry without saturating the database, while guaranteeing that revoked or rotated credentials lose access within 5 seconds.

---

## 3. Setup Guide

### Step 1: Prepare the Root `.env` File

If you don't already have a `.env` file in the project root (`LiteBoard/.env`), copy from `.env.example`:

```powershell
Copy-Item .env.example .env
```

### Step 2: Configure Environment Variables

Configure the following variables in the root `.env` based on your setup:

#### Scenario A — Full Docker Stack (PostgreSQL running inside Docker Compose)
```dotenv
POSTGRES_DB=liteboard
POSTGRES_USER=liteboard
POSTGRES_PASSWORD=your-strong-db-password
POSTGRES_PORT=5432

# Point Mosquitto to the Docker postgres container
MQTT_PG_HOST=postgres

# Internal accounts (use strong unique secrets in Production)
MQTT_INTERNAL_USERNAME=telemetry-service
MQTT_INTERNAL_PASSWORD=change-me-telemetry-service-password
MQTT_HEALTHCHECK_USERNAME=healthcheck
MQTT_HEALTHCHECK_PASSWORD=change-me-healthcheck-password
```

#### Scenario B — Local Hybrid Development (PostgreSQL running natively on the host machine)
If your `backend/.env` connects to a PostgreSQL instance installed on your host machine (`127.0.0.1:5432`), set `MQTT_PG_HOST=host.docker.internal` so the Mosquitto container queries the same database:

```dotenv
POSTGRES_DB=liteboard
POSTGRES_USER=postgres
POSTGRES_PASSWORD=<your-host-postgres-password>
POSTGRES_PORT=5432

# Route Mosquitto container traffic to the host machine's PostgreSQL
MQTT_PG_HOST=host.docker.internal

MQTT_INTERNAL_USERNAME=telemetry-service
MQTT_INTERNAL_PASSWORD=telemetry-service-local-secret
MQTT_HEALTHCHECK_USERNAME=healthcheck
MQTT_HEALTHCHECK_PASSWORD=healthcheck-local-secret
```

### Step 3: Run Database Migrations

Ensure your database has the `devices`, `device_credentials` tables and the `v_mqtt_users`, `v_mqtt_acls` SQL views:

```powershell
cd backend
php artisan migrate
```

### Step 4: Start Mosquitto & Redis

From the project root:

```powershell
docker compose up -d redis mosquitto
```

Verify container readiness:

```powershell
docker compose ps mosquitto
docker compose logs --tail=30 mosquitto
```

When healthy, the status of `mosquitto` will report **`healthy`** with the following log markers:
```text
level=info msg="Backend registered: Files"
level=info msg="backend registered: Postgres"
level=info msg="redisCache activated"
```

---

## 4. Testing & Verification

### 4.1. Verify Anonymous Connections Are Blocked (Expected: Refused)
```powershell
docker compose exec -T mosquitto mosquitto_pub -h 127.0.0.1 -t test -m "hello"
# Result: Connection error: Connection Refused: not authorised.
```

### 4.2. Publish Telemetry Using a Device Access Token
After registering a device in LiteBoard and obtaining its `ACCESS_TOKEN`, `PROJECT_ID`, and `DEVICE_ID`:

```powershell
docker compose exec -T mosquitto mosquitto_pub `
  -h 127.0.0.1 -p 1883 -q 1 `
  -u "<ACCESS_TOKEN>" -P "<ACCESS_TOKEN>" `
  -t "liteboard/<PROJECT_ID>/devices/<DEVICE_ID>/telemetry" `
  -m '{"temperature": 26.5}'
```

### 4.3. Test Internal Telemetry Listener
```powershell
docker compose exec -T mosquitto mosquitto_sub `
  -h 127.0.0.1 -p 1883 `
  -u "telemetry-service" -P "<MQTT_INTERNAL_PASSWORD>" `
  -t "liteboard/#" -v
```

### 4.4. Troubleshooting
* **`pq: relation "v_mqtt_users" does not exist` in `docker compose logs mosquitto`**:
  * Cause: Either `php artisan migrate` was not run, or `MQTT_PG_HOST` is pointing to an empty Docker `postgres` volume instead of the active database used by Laravel.
  * Resolution: Check `MQTT_PG_HOST`, `POSTGRES_USER`, and `POSTGRES_PASSWORD` in `.env` to ensure they match `backend/.env`, then run `docker compose up -d --force-recreate mosquitto`.
* **Changes in `.env` not reflected in Mosquitto**:
  * Resolution: Run `docker compose up -d --force-recreate mosquitto` to reload environment variables and regenerate `/tmp/mosquitto-passwords`.
