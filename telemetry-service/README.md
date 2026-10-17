# Telemetry Service

`telemetry-service` is LiteBoard's MQTT data-plane worker. Laravel remains the control plane for Projects, Devices, Profile Versions, credentials, tenant ownership, and the REST telemetry API. Telemetry samples, latest values, and ingest outcomes are tenant-owned through their Project and Device foreign keys. Unresolved messages whose scope the database cannot confirm are central data and are visible only to platform admins.

## Code layout

| Path | Responsibility |
| --- | --- |
| `app/ingress/mqtt_subscriber.py` | MQTT v5 client, topic parsing, PUBACK timing, post-commit broadcast |
| `app/telemetry/envelope.py` | Envelope parsing, payload limits, canonical content hash, future-skew check |
| `app/telemetry/validation.py` | Profile Version schema validation and rejection reasons |
| `app/telemetry/decisions.py` | Pure ingest rules: unresolved classification, duplicate/conflict, admit or reject |
| `app/storage/telemetry_ingest.py` | Runs decisions inside one PostgreSQL transaction: reads state, takes locks, writes rows |
| `app/storage/queries.py` | All SQL statements |
| `app/realtime/` | WebSocket ticket verification, in-process fan-out hub, `/ws/telemetry` endpoint |
| `app/pipeline/` | Stage runner used by the heartbeat path |

Business rules live in `app/telemetry/` and import nothing from asyncpg; test them with plain unit tests. The storage layer keeps the transaction invariants (the `FOR NO KEY UPDATE` device lock, taken at the strength the presence update needs so concurrent messages from one Device queue instead of deadlocking, the advisory lock around dedup, the `ON CONFLICT … WHERE` latest-value guard) in SQL on purpose; do not hide them behind a repository interface.

## Processing

The service uses MQTT v5 and `gmqtt==0.7.0`. Run one consumer replica per `MQTT_CLIENT_ID`; the default stable ID is `liteboard-telemetry-service`. The client uses a persistent session and disables optimistic acknowledgements. QoS 1 callbacks return PUBACK success only after the message transaction commits. If PostgreSQL fails, the callback raises without acknowledging, then reconnects with the same client ID and session so the broker can redeliver.

Telemetry follows this transaction boundary:

```text
assign processing_id, record "received"
resolve active, approved Device + active Project and Tenant
  ├─ not resolved → classify (see Unresolved messages) → write outcome or central row → commit
  └─ resolved
       → mark Device presence                          "resolved"
       ├─ decode and normalize payload                       "decoded"
       → reject retained telemetry
       → dedup by (device_id, message_id) under lock   "dedup_checked"
       ├─ no Profile Version
       │    → validate basic metadata and scalar metrics
       │    → skip only Profile Version schema validation     "schema_validation_skipped"
       └─ assigned Profile Version
            → validate against the assigned Profile Version "validated"
       → write sample + latest values + dedup identity + outcome, or a rejection outcome   "commit"
       → commit
→ broadcast the recorded outcome and any committed sample to WebSocket subscribers
→ return MQTT PUBACK success
```

For a Device with an assigned Profile Version, the envelope requires a nonempty `message_id` of at most 128 characters, a positive integer `schema_version`, a timezone-aware ISO 8601 `occurred_at`, and an object-valued `values`. Payloads are limited to 64 KiB. Timestamps more than five minutes in the future are rejected; late data remains valid. The schema version must match the Profile Version currently assigned to that Device. Retained telemetry is rejected before dedup for both profile and profile-less Devices.

A Device without an assigned Profile Version can send either flat metrics such as `{"temperature":25.12,"humidity":60}` or a wrapped object such as `{"values":{"temperature":25.12,"humidity":60}}`. `values`, when present, must be a nonempty object and the only metric source. Otherwise, root fields other than `message_id`, `occurred_at`, and `schema_version` are metrics. Mixed metric sources, empty metrics, `null`, objects, arrays, and non-finite numbers are rejected; scalar numbers, strings, and booleans are stored. Missing `message_id` is generated as a UUID and missing `occurred_at` uses the transaction's `received_at`. Supplied metadata must be valid and non-null; a supplied `schema_version` must be a positive integer but is reserved and not used for schema selection. `occurred_at` must be ISO 8601 with timezone and no more than five minutes in the future. The sample is persisted with `device_profile_version_id = null`, latest values and WebSocket events are updated, and the outcome is `persisted` with no reasons.

For a client-supplied ID, deduplication hashes the canonical original payload before server-generated metadata: same ID and content is a duplicate, while changed content is an idempotency conflict. A server-generated ID creates a new logical message on every receipt, so retries without a client ID are not deduplicated. Historical `skipped` outcomes remain readable; new profile-less messages no longer use that outcome.

Samples store the Project, Device, nullable Profile Version, message ID, UTC occurrence time, database receipt time, JSONB values, and `persisted` status. A canonical JSON SHA-256 hash makes an identical client-supplied `(device_id, message_id)` a `duplicate`; changed content under that identity becomes `idempotency_conflict`. Rejections, duplicates, conflicts, and unresolved messages record only metadata and reason codes, never the raw payload.

The service subscribes with `retain_as_published=True` and rejects retained telemetry based on MQTT v5's `retain` property, before dedup so a retained replay is never counted as a duplicate. This check is channel-specific; retained heartbeat handling remains unchanged. Resolved Devices update presence before payload or schema validation.

### Outcomes and trace

Every handled message gets a `processing_id`. Outcomes in `telemetry_ingest_outcomes` store it with a `stages` timeline (`received`, `resolved`, `decoded`, `dedup_checked`, `validated` or `schema_validation_skipped`, `commit`; earlier exits record fewer stages). After commit, when at least one WebSocket subscriber receives the outcome event, a best-effort `broadcast` stage with the subscriber count is appended outside the ingest transaction. A committed sample is sent as a separate event for the same message. A failure to append the stage is only logged. At most 256 stage writes can be in flight; beyond that they are dropped with a warning.

| Status | Meaning |
| --- | --- |
| `persisted` | Sample stored |
| `rejected` | Envelope, retained-message, or schema-validation rejection |
| `duplicate` | Same `(device_id, message_id)` and content as a stored sample |
| `idempotency_conflict` | Same `(device_id, message_id)` with different content |
| `unresolved` | The Device exists in the topic's Project but is blocked |
| `skipped` | Historical outcome for an active Device without an assigned Profile Version; new messages with basic-valid payloads are persisted |

### Latest values

Each persisted sample upserts one `telemetry_latest_values` row per metric in the same transaction. A row only moves forward in `(occurred_at, received_at, sample_id)`, so a late sample never overwrites a newer value and a metric missing from the newest sample keeps its last value. Laravel serves these rows from `GET …/devices/{device}/telemetry/latest`.

### Unresolved messages

Topic IDs are untrusted. A tenant-owned row is written only when the database confirms the topic's `(project_id, device_id)` pair:

| Case | Where it is recorded | Reason |
| --- | --- | --- |
| Device exists in the topic's Project but is pending or rejected | `telemetry_ingest_outcomes`, status `unresolved` | `device_not_approved` |
| … Device inactive | same | `device_inactive` |
| … Project inactive | same | `project_inactive` |
| … Tenant inactive | same | `tenant_inactive` |
| Topic does not match `liteboard/{uuid}/devices/{uuid}/{channel}` | `telemetry_unresolved_messages` (central) | `invalid_topic` |
| No Device with that ID | same | `device_not_found` |
| Device belongs to another Project | same | `project_mismatch` |

Unresolved messages create no sample and do not update presence. Central rows keep only the topic string, reason, payload size, and stages. They have no retention job yet. A failed write for an invalid topic is logged and the message is still acknowledged, because only the trace record is lost.

### Realtime WebSocket

`/ws/telemetry?ticket=…` streams committed samples and device-scoped ingest outcomes for one Device. `telemetry.sample` matches the REST sample resource and is deduplicated by `data.id`. `telemetry.outcome` matches the REST message outcome resource and is deduplicated by `data.processing_id`. Both are emitted after the ingest transaction commits; neither event includes the raw MQTT payload. Central unresolved rows that cannot be scoped to an existing Device are not sent on this stream.

- Laravel issues tickets from `POST …/devices/{device}/telemetry/stream-ticket` after checking `DevicePolicy::view`. A ticket is HMAC-SHA256 signed with `TELEMETRY_WS_SECRET` and expires after 60 seconds.
- Before expiry, the client sends `{"type": "renew", "ticket": "…"}` with a fresh ticket for the same user and Device. Revoked access therefore takes effect within 60 seconds.
- Each subscriber has a 256-event queue. A full queue disconnects the subscriber instead of growing memory.
- Events published while a client is disconnected are not replayed. After every (re)connect, clients fetch the gap from REST history and recent message outcomes, merging duplicate outcomes by `processing_id`.
- Fan-out is in-process, which matches the one-replica-per-client-ID deployment. Multiple replicas would need a shared bus.

| Close code | Cause |
| --- | --- |
| `4401` | Missing, forged, or expired ticket on connect |
| `4403` | Ticket expired without renewal, invalid renewal, or renewal for another user/Device |
| `4008` | Slow consumer: the subscriber queue overflowed |
| `1013` | Connection cap reached |
| `1011` | `TELEMETRY_WS_SECRET` is not configured (ingestion still runs) |

`GET /health` reports process liveness. `GET /ready` probes PostgreSQL and the MQTT connection. REST history, aggregates, latest values, and message trace are served by Laravel. The service does not provide rule evaluation, automatic retention, TimescaleDB, Redis queues, or write batching. Committed samples are the durable source Phase 5 can replay.

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
| `TELEMETRY_WS_SECRET` | empty | Shared ticket secret; must equal Laravel's value and be at least 32 characters, otherwise WebSocket connections close with `1011` |
| `TELEMETRY_WS_MAX_CONNECTIONS` | `500` | Total WebSocket subscriber cap |

The PostgreSQL pool uses `min_size=1` and `max_size=5`.

**Run the Laravel migrations before starting a new service version.** The ingest SQL writes `processing_id`, `stages`, `telemetry_latest_values`, and `telemetry_unresolved_messages`. Against an older schema every ingest fails, nothing is acknowledged, and the broker keeps redelivering.

## Tests and acceptance

Run unit tests from this directory:

```powershell
python -m unittest discover -s tests -p "test_*.py"
```

- `test_decisions.py`, `test_envelope.py`, and `test_validate_telemetry.py` cover the pure rules.
- `test_telemetry_ingest.py` checks how decisions are written and the transaction boundaries, using a fake connection.
- WebSocket endpoint tests in `test_realtime.py` need `httpx` for Starlette's `TestClient`. It is not in `requirements.txt`, so install it in your development environment; without it those tests are skipped.

PostgreSQL integration tests use a separate database with Laravel migrations already applied. Set `TELEMETRY_TEST_DATABASE_URL` to that disposable database before running the command. They cover persistence, dedup, latest-value ordering with late samples and missing metrics, the stage timeline, and both unresolved paths. Mosquitto integration tests require `TELEMETRY_TEST_MQTT_HOST`, `TELEMETRY_TEST_MQTT_CA_FILE`, `TELEMETRY_TEST_MQTT_USERNAME`, and `TELEMETRY_TEST_MQTT_PASSWORD`; they verify QoS 1 redelivery after a simulated storage failure and retained telemetry handling.

Run the development L0 against a disposable migrated PostgreSQL database and an isolated Mosquitto broker with anonymous MQTT access and the backlog limits above:

```powershell
$env:TELEMETRY_TEST_DATABASE_URL = 'postgresql://user:password@localhost:55432/liteboard'
$env:MQTT_HOST = 'localhost'
$env:MQTT_PORT = '18884'
$env:TELEMETRY_L0_INSECURE_TEST_BROKER = '1'
python tests/run_l0_load.py
```

The runner sends 10 unique Device streams at one message per second for five minutes, reconciles logical messages against committed outcomes, reports p50/p95/p99 transaction latency, database latency, maximum active callbacks, uncommitted messages and Python memory, then runs a separate QoS 1 redelivery recovery probe.

The last recorded local development run predates IT-4. It persisted 3,000/3,000 messages with zero unexplained outcomes:

| Metric | Result |
| --- | --- |
| Transaction p50/p95/p99 | 11.189 / 24.836 / 43.171 ms |
| Average database latency | 14.005 ms |
| Maximum active callbacks and uncommitted messages | 3 |
| Python tracemalloc peak | 0.705 MiB |
| Recovery | 6.053 seconds |

IT-4 adds a latest-value upsert per sample and stage tracking, so rerun L0 before relying on these numbers. Per-stage latency can be measured from the `stages` column; see the IT-4 spec for a query. These results use an isolated local broker/database and do not claim pilot capacity; broker queue depth and runtime fault injection still need verification in the target deployment.
