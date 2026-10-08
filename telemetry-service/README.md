# Telemetry Service

`telemetry-service` is LiteBoard's MQTT data-plane worker. It consumes Device telemetry and heartbeat messages, then updates the Device presence fields in PostgreSQL. Laravel remains the control plane for Projects, Devices, credentials, and tenant ownership.

## Architecture

The service uses Ports and Adapters, an Application Service, and constructor injection. `main.py` is the composition root and owns the resource lifecycle.

```text
Mosquitto
   │ MQTT over TLS; QoS 1 subscriptions
   ▼
MqttSubscriber                         app/adapters/mqtt_subscriber.py
   ├─ parses the topic and validates UTF-8 JSON objects
   ▼
UpdateDevicePresence                  app/application/update_device_presence.py
   ├─ calls PresenceWriter
   └─ prints message metadata after the database operation succeeds
        │
        ▼
PresenceWriter                        app/application/presence_writer.py
        ▲
        │ implemented by
PostgresPresenceWriter                 app/adapters/postgres_presence_writer.py
   └─ updates Device presence in PostgreSQL

main.py creates the PostgreSQL pool and wires the writer, application service,
and MQTT subscriber together. It also exposes the HTTP health endpoints.
```

`PresenceWriter` is a Python `Protocol`. The application service depends on that interface rather than on PostgreSQL or MQTT. The subscriber owns MQTT connection, authentication, TLS, subscriptions, topic parsing, and payload checks. The PostgreSQL adapter borrows connections from the pool; it does not own the pool lifecycle.

## Current message behavior

- Subscribes to `liteboard/+/devices/+/telemetry` and `liteboard/+/devices/+/heartbeat` at QoS 1.
- Accepts only five-segment topics with valid Project and Device UUIDs and a `telemetry` or `heartbeat` channel.
- Accepts payloads that decode as UTF-8 JSON objects. An empty object (`{}`) is valid. Invalid topics and payloads are ignored.
- Updates `connection_state`, `last_seen_at`, and `updated_at` only where both the Device ID and Project ID match and the Device is active. The Project ID comes from the topic and is used as a second database condition.
- Prints Project ID, Device ID, channel, and payload byte size after the database operation completes. A zero-row update still prints metadata; a database error propagates and prevents the success metadata from being printed.

This service currently updates presence only. It does not persist telemetry samples or implement schema validation, deduplication, queues, realtime delivery, or intrusion detection. `/ready` reports the current static readiness response and timestamp; it does not probe MQTT or PostgreSQL dependencies.

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
