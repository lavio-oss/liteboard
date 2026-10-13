# Telemetry Service

`telemetry-service` is LiteBoard's MQTT data-plane worker. Laravel remains the control plane for Projects, Devices, Profile Versions, credentials, and tenant ownership. Telemetry samples and ingest outcomes are tenant-owned through their Project and Device foreign keys.

## Processing

The service uses MQTT v5 and `gmqtt==0.7.0`. Run one consumer replica per `MQTT_CLIENT_ID`; the default stable ID is `liteboard-telemetry-service`. The client uses a persistent session and disables optimistic acknowledgements. QoS 1 callbacks return PUBACK success only after the message transaction commits. If PostgreSQL fails, the callback raises without acknowledging, then reconnects with the same client ID and session so the broker can redeliver.

Telemetry follows this transaction boundary:

```text
resolve active, approved Device + active Project and Tenant
  → mark Device presence
  → validate the envelope and assigned Profile Version
  → write sample + dedup identity + outcome, or rejection outcome
  → commit
  → return MQTT PUBACK success
```

The envelope requires a nonempty `message_id` of at most 128 characters, a positive integer `schema_version`, a timezone-aware ISO 8601 `occurred_at`, and an object-valued `values`. Payloads are limited to 64 KiB. Timestamps more than five minutes in the future are rejected; late data remains valid. The schema version must match the Profile Version currently assigned to that Device.

Samples store the Project, Device, assigned Profile Version, message ID, UTC occurrence time, database receipt time, JSONB values, and `persisted` status. A canonical JSON SHA-256 hash makes an identical `(device_id, message_id)` a `duplicate`; changed content under that identity becomes `idempotency_conflict`. Rejections, duplicates, and conflicts record only outcome metadata and reason codes, never the raw payload. Messages that cannot resolve to an active approved Device in an active Project and Tenant create no tenant-owned row.

The service subscribes with `retain_as_published=True` and rejects retained telemetry based on MQTT v5's `retain` property. This check is channel-specific; retained heartbeat handling remains unchanged. Telemetry updates presence before envelope and schema validation, so a valid Device remains online when its telemetry is rejected.

`GET /health` reports process liveness. `GET /ready` probes PostgreSQL and the MQTT connection. The service does not provide REST history/latest, WebSocket updates, rule evaluation, automatic retention, TimescaleDB, Redis queues, or write batching. Committed samples are the durable source Phase 5 can replay.

## Delivery limits

The defaults bound in-flight and offline broker work:

| Setting | Default | Purpose |
| --- | ---: | --- |
| `MQTT_SESSION_EXPIRY_SECONDS` | `2147483647` | Keep the persistent MQTT session across worker restarts |
| `MQTT_RECEIVE_MAXIMUM` | `20` | Bound unacknowledged messages delivered to the subscriber |
| Mosquitto `max_inflight_messages` | `20` | Bound broker-side unacknowledged QoS messages |
| Mosquitto `max_queued_messages` | `1000` | Bound each offline session's queued message count |
| Mosquitto `max_queued_bytes` | `67108864` | Bound queued message bytes to 64 MiB |

Hitting a broker queue limit is a degraded condition. Do not count an L0 run as passing if messages are dropped because the configured backlog limit was reached.

## Configuration

Docker Compose supplies these environment variables. Keep credentials in the local environment configuration and out of source control.

| Variable | Default in the Python service | Purpose |
| --- | --- | --- |
| `MQTT_HOST` | `localhost` | Mosquitto TLS hostname |
| `MQTT_PORT` | `8883` | Mosquitto TLS port |
| `MQTT_TLS_CA_FILE` | `/run/secrets/mqtt_tls_ca_certificate` | CA certificate used to verify the broker |
| `MQTT_USERNAME` | empty | If set, configures MQTT username authentication |
| `MQTT_PASSWORD` | empty | MQTT password paired with `MQTT_USERNAME` |
| `MQTT_CLIENT_ID` | `liteboard-telemetry-service` | Stable ID for the persistent consumer session |
| `MQTT_SESSION_EXPIRY_SECONDS` | `2147483647` | MQTT v5 persistent-session lifetime |
| `MQTT_RECEIVE_MAXIMUM` | `20` | MQTT v5 subscriber receive window |
| `DB_HOST` | `localhost` | PostgreSQL host |
| `DB_PORT` | `5432` | PostgreSQL port |
| `DB_DATABASE` | `liteboard` | PostgreSQL database |
| `DB_USERNAME` | `liteboard` | PostgreSQL user |
| `DB_PASSWORD` | `liteboard` | PostgreSQL password |

The PostgreSQL pool uses `min_size=1` and `max_size=5`. Apply the Laravel migration before starting telemetry ingestion.

## Tests and acceptance

Run unit tests from this directory:

```powershell
python -m unittest discover -s tests -p "test_*.py"
```

PostgreSQL integration tests use a separate database with Laravel migrations already applied. Set `TELEMETRY_TEST_DATABASE_URL` to that disposable database before running the command. Mosquitto integration tests require `TELEMETRY_TEST_MQTT_HOST`, `TELEMETRY_TEST_MQTT_CA_FILE`, `TELEMETRY_TEST_MQTT_USERNAME`, and `TELEMETRY_TEST_MQTT_PASSWORD`; they verify QoS 1 redelivery after a simulated storage failure and retained telemetry handling.

Run the development L0 against a disposable migrated PostgreSQL database and an isolated Mosquitto broker with anonymous MQTT access and the backlog limits above:

```powershell
$env:TELEMETRY_TEST_DATABASE_URL = 'postgresql://user:password@localhost:55432/liteboard'
$env:MQTT_HOST = 'localhost'
$env:MQTT_PORT = '18884'
$env:TELEMETRY_L0_INSECURE_TEST_BROKER = '1'
python tests/run_l0_load.py
```

The runner sends 10 unique Device streams at one message per second for five minutes, reconciles logical messages against committed outcomes, reports p50/p95/p99 transaction latency, database latency, maximum active callbacks, uncommitted messages and Python memory, then runs a separate QoS 1 redelivery recovery probe. The local development run persisted 3,000/3,000 messages with zero unexplained outcomes; transaction p50/p95/p99 was 11.189/24.836/43.171 ms, average database latency 14.005 ms, maximum active callbacks and uncommitted messages 3, Python tracemalloc peak 0.705 MiB, and recovery 6.053 seconds. These results use an isolated local broker/database and do not claim pilot capacity; broker queue depth and runtime fault injection still need verification in the target deployment.
