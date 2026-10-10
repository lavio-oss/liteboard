# Telemetry Service

`telemetry-service` is LiteBoard's MQTT data-plane worker. It consumes Device telemetry and heartbeat messages, then updates the Device presence fields in PostgreSQL. Laravel remains the control plane for Projects, Devices, credentials, and tenant ownership.

## Architecture

Each MQTT message runs through a **pipeline**: an ordered list of async stages that share one `MessageContext`. `main.py` is the composition root: it creates the PostgreSQL pool, the repositories, and one pipeline per channel.

```text
Mosquitto ── MQTT over TLS, QoS 1 ──▶ MqttSubscriber          app/ingress/mqtt_subscriber.py
                                         parses the topic, builds MessageContext,
                                         runs the pipeline for its channel
                                              │
                                              ▼
                                      run_pipeline            app/pipeline/runner.py
                                         runs stages in order, stops at the first rejection,
                                         prints one outcome line per message

heartbeat: decode_json → MarkPresence
telemetry: decode_json → MarkPresence → ValidateSchema       app/pipeline/stages.py

validate_telemetry (pure)                                     app/telemetry/validation.py
PostgresPresenceWriter, PostgresDeviceSchemaReader            app/storage/
```

A stage is `async (ctx) -> None`. It rejects by calling `ctx.reject(Rejection(code, field))`. Infrastructure errors such as a database failure are not caught: they propagate, and no outcome line is printed. Repositories borrow connections from the pool; they do not own its lifecycle. New processing steps (normalize, persist, rules) are added as stages to the channel list in `main.py`.

## Current message behavior

- Subscribes to `liteboard/+/devices/+/telemetry` and `liteboard/+/devices/+/heartbeat` at QoS 1.
- Accepts only five-segment topics with valid Project and Device UUIDs and a `telemetry` or `heartbeat` channel. Other topics are ignored silently.
- Payloads must decode as UTF-8 JSON objects (`{}` is valid); otherwise the message is rejected with `invalid_json`.
- Updates `connection_state`, `last_seen_at`, and `updated_at` only where both the Device ID and Project ID match and the Device is active. Presence is updated even when schema validation later rejects the payload.
- Telemetry is validated against the Device's assigned profile version, looked up by Device ID and Project ID from the topic. Reason codes: `invalid_json`, `invalid_payload`, `schema_not_assigned`, `schema_version_mismatch`, `missing_required_field`, `unknown_field`, `invalid_type`, `below_minimum`, `above_maximum`, `value_not_allowed`.
- Prints one line per message: `project_id`, `device_id`, `channel`, `payload_size`, `outcome` (`accepted`/`rejected`), and `reasons`.

The service does not persist telemetry samples or outcomes, deduplicate messages, queue work, or deliver realtime updates. PUBACK is sent before processing (gmqtt optimistic acknowledgement) and the MQTT session is clean, so a crash can lose in-flight messages. `/ready` reports a static readiness response and timestamp; it does not probe MQTT or PostgreSQL.

## Configuration

Docker Compose supplies these environment variables. Keep credentials in the local environment configuration and out of source control.

| Variable | Default in the Python service | Purpose |
| --- | --- | --- |
| `MQTT_HOST` | `localhost` | Mosquitto TLS hostname |
| `MQTT_PORT` | `8883` | MQTT TLS port |
| `MQTT_TLS_CA_FILE` | `/run/secrets/mqtt_tls_ca_certificate` | CA certificate used to verify the broker |
| `MQTT_USERNAME` | empty | If set, configures MQTT username authentication |
| `MQTT_PASSWORD` | empty | MQTT password paired with `MQTT_USERNAME` |
| `DB_HOST` | `localhost` | PostgreSQL host |
| `DB_PORT` | `5432` | PostgreSQL port |
| `DB_DATABASE` | `liteboard` | PostgreSQL database |
| `DB_USERNAME` | `liteboard` | PostgreSQL user |
| `DB_PASSWORD` | `liteboard` | PostgreSQL password |

The PostgreSQL pool is created with `min_size=1` and `max_size=5`. The MQTT client ID is `liteboard-telemetry-service`. On shutdown, the service disconnects the MQTT client and closes the pool.

## HTTP endpoints

- `GET /health` returns the service name and `ok` status.
- `GET /ready` returns the service name, `ready` status, and a UTC `checked_at` timestamp. It currently does not check dependency connectivity.

## Tests

Run the service's unit and adapter tests from this directory:

```powershell
python -m unittest discover -s tests -p "test_*.py"
```

The tests use mocks for MQTT and PostgreSQL. Passing them confirms the service's code behavior; it does not demonstrate connectivity to a live broker, database, or Device.
