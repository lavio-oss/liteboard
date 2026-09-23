# LiteBoard — Development, Technical & Deployment Plan

## 1. Product Identity

**LiteBoard** is a lightweight, open-source and developer-first IoT backend for MQTT devices. It focuses on device connectivity, structured telemetry, real-time monitoring, condition-based rules, industrial equipment integration and operational visibility.

LiteBoard is not intended to become a full ThingsBoard replacement, SCADA platform, MES or ERP. It provides an understandable IoT foundation that developers can deploy quickly and connect to EAMO, MES, ERP or custom applications.

### Core positioning

> **LiteBoard — A lightweight, developer-first IoT backend for MQTT devices.**

### Main use case

~~~text
ESP32 / Edge Device
        ↓
       MQTT
        ↓
     LiteBoard
        ↓
Telemetry Validation
        ↓
Telemetry Storage
        ↓
Rule / Condition Engine
        ↓
Alert / Event
        ↓
EAMO / MES / ERP / Custom App
~~~

### Product goals

LiteBoard should help developers:

- Register, authenticate and manage equipment endpoints.
- Define explicit telemetry schemas.
- Receive, validate and store MQTT telemetry.
- Monitor equipment state in real time.
- Inspect the lifecycle of each MQTT message.
- Define condition-based rules and generate alerts.
- Send events through webhooks.
- Send commands back to equipment endpoints.
- Deploy the complete platform with Docker.

The project is also a signature backend engineering project demonstrating Industrial IoT, real-time systems, networking, databases, event-driven processing and observability.

---

## 2. Scope and Non-Goals

### Initial scope

~~~text
MQTT
Equipment
Projects
Equipment Profiles
Telemetry Schemas
Telemetry
Rules
Alerts
Webhooks
Commands
Message Inspection
Observability
APIs
~~~

### Non-goals for the first versions

LiteBoard should not initially become:

- A complete SCADA, MES or ERP system.
- A large dashboard builder or low-code workflow platform.
- A platform that runs arbitrary user Python or JavaScript code.
- A multi-protocol IoT platform from day one.
- A Kubernetes, Kafka or multi-region deployment exercise.
- A custom MQTT broker, mobile app or advanced ML platform.

These features are deferred until real requirements justify them.

---

## 3. Engineering Principles

### Simple to start

A developer should be able to run:

~~~bash
docker compose up -d
~~~

and connect an ESP32 or simulator within minutes.

### Observable by design

Every telemetry message should have a traceable processing path:

~~~text
MQTT Received
     ↓
Authentication
     ↓
Payload Decode
     ↓
Schema Validation
     ↓
Normalization
     ↓
Rule Evaluation
     ↓
Telemetry Storage
     ↓
Realtime Broadcast
~~~

### Schema-first and API-first

Telemetry must have an explicit, versioned structure before it enters the platform.

~~~yaml
MotorTelemetry:
  temperature:
    type: float
    unit: celsius
    min: -20
    max: 150
  vibration:
    type: float
    unit: mm/s
  rpm:
    type: integer
    min: 0
~~~

Invalid data is rejected or recorded with a structured reason. The frontend consumes the same REST and WebSocket APIs available to external applications.

### Measurable engineering

Every major feature should have:

~~~text
Requirement
Design
Implementation
Test
Metrics
~~~

Use batching, indexing, caching, partitioning, backpressure and horizontal workers in response to measured bottlenecks. Avoid premature complexity.

---

## 4. Technical Baseline

LiteBoard uses a Control Plane and a Data Plane so equipment management and high-frequency telemetry processing can evolve independently.

### Control Plane

- PHP 8.4
- Laravel 13
- REST API
- Authentication and RBAC
- Projects, tenants and industrial hierarchy
- Equipment endpoints, profiles and schemas
- Rules, alerts, webhooks and commands
- Laravel Events, Policies, Scheduler and Queue integration

### Data Plane

- Python 3.12+
- FastAPI for data-plane health and service endpoints
- Paho MQTT or gmqtt
- asyncio
- Pydantic
- MQTT consumption, validation, normalization and ingestion
- Buffering, batching and processing metrics

### Supporting stack

- EMQX as the primary MQTT broker; Mosquitto is suitable for local development.
- Vue 3, TypeScript, Pinia, Ant Design Vue and ECharts.
- PostgreSQL for application data and initial telemetry storage.
- TimescaleDB when time-series benchmarks justify it.
- Redis for equipment state, queues and pub/sub.
- Docker Compose, Nginx and Linux VPS.
- Prometheus, Grafana, structured logs and optional OpenTelemetry.
- ESP32 reference device and Python device simulator.

LiteBoard connects to the MQTT broker and handles application-level IoT logic; it does not implement a custom broker.

---

## 5. High-Level Architecture

~~~text
                         ┌─────────────────────────┐
                         │        Vue 3 UI         │
                         │   LiteBoard Dashboard   │
                         └────────────┬────────────┘
                                      │ REST / WebSocket
                                      ▼
                         ┌─────────────────────────┐
                         │       Laravel API       │
                         │      Control Plane      │
                         │ Tenants / Projects     │
                         │ Equipment / Hierarchy  │
                         │ Schemas / Rules         │
                         │ Alerts / Commands       │
                         └────────────┬────────────┘
                                      │
                         PostgreSQL / Redis / Events
                                      │
ESP32 / Simulator ── MQTT ──► EMQX / Mosquitto
                                      │
                                      ▼
                         ┌─────────────────────────┐
                         │ Python MQTT/Data Plane   │
                         │ Auth / Decode            │
                         │ Schema Validation        │
                         │ Normalization             │
                         │ Buffer / Batch Insert    │
                         └────────────┬────────────┘
                                      │
                           PostgreSQL / TimescaleDB
                                      │
                                      ▼
                              Rule / State Events
                                      │
                              Redis / WebSocket
~~~

Message contract:

~~~text
Equipment Endpoint
  → MQTT Broker
  → Authentication
  → JSON Decode
  → Schema Lookup
  → Type and Range Validation
  → Normalization
  → Buffer / Queue
  → Database
  ├→ Rule Engine
  ├→ Alert / Webhook Events
  └→ WebSocket Broadcast
~~~

---

## 6. Domain Model

LiteBoard is a Control Plane intermediary for IIoT resources. The industrial
hierarchy is part of the core database model, even though this project does not
implement a complete SCADA/MES platform.

### 6.0 Backend implementation status — 2026-09-23

The tenant-owned master-data foundation is implemented in the Laravel backend
as the standalone `Masterdata` module. `Tenant` remains in `Core`; `Project` is
a workspace and the authorization boundary for resources below the tenant.
The current implemented subtree is:

```text
Tenant
└── Project workspace
    └── Asset graph
        ├── Site
        ├── Factory
        ├── Area
        ├── Production Line
        ├── Station
        └── Equipment
```

All six entries are values of `AssetType` and are stored in the `assets` table.
`Equipment` is not a separate table. The asset hierarchy uses the single
`contains` relation in `entity_relations`, with forward-only migrations,
scoped nested CRUD routes, request validation, resources, policies and feature
tests.

`Device`, MQTT identity, credentials, firmware, telemetry and commands are
later phases. They are not part of this master-data implementation.

~~~text
Tenant
 └── Project workspace
      └── Asset graph (contains)
           ├── Site
           ├── Factory
           ├── Area
           ├── Production Line
           ├── Station
           └── Equipment
~~~

Core entities:

- Tenant: isolation boundary for users, projects and all resources below it.
- Project: logical workspace belonging to exactly one tenant.
- Asset: a tenant-owned master-data node belonging to exactly one project.
- AssetType: `site`, `factory`, `area`, `production_line`, `station` or
  `equipment`.
- Equipment: an `AssetType` value; it has no separate table or device identity
  in this phase.
- Telemetry Schema: field types, units, ranges, required state, descriptions and version.
- Telemetry: normalized time-series data with message ID and processing status.
- Rule: condition configuration evaluated against validated telemetry.
- Alert: actionable event created by a rule or equipment-state condition.
- Command: equipment message with status, timeout and acknowledgement history.

Suggested fields:

~~~text
Tenant: id, name, code, status
Project: id, tenant_id, name, description, status, timestamps
Asset: id, project_id, asset_type, name, code, location, status, timestamps
EntityRelation: project_id, source_type, source_id, target_type, target_id,
                relation_type, timestamps
~~~

### 6.1 Asset hierarchy database contract

`assets.project_id` is required and asset codes are unique within
`project_id + asset_type + code`. An asset may be a root or have one parent.
The source of truth for hierarchy is `entity_relations`:

~~~text
entity_relations
  project_id    FK NOT NULL → projects.id
  source_type   = asset
  source_id     → parent asset identity
  target_type   = asset
  target_id     → child asset identity
  relation_type = contains
~~~

This phase accepts only `asset contains asset`. A unique constraint on
`project_id + target_type + target_id + relation_type` allows at most one
incoming `contains` relation per asset. Source, target and project indexes
support hierarchy traversal and scoped list queries.

`parent_asset_id` remains in the request and resource contract as a derived
projection for the existing frontend UX. It is not a database column and is
never a second source of truth.

The request/domain boundary rejects a parent from another project,
self-parenting and cycles. Asset create, update and parent changes run in a
transaction. Deleting an asset removes its relations, keeps its children and
makes those children roots. Tenant and project scopes are resolved from route
binding; request bodies do not provide `tenant_id` or `project_id`.

The asset child relation is derived from the incoming `contains` relation:

~~~text
parent contains child
child.parent_asset_id = parent.id (API projection only)
~~~

The relation table is the only hierarchy source of truth. `parent_asset_id` is
an API/UI projection only; do not add it back as a storage column. This phase
supports only the `contains` relation and requires the source and target to be
assets in the same project.

The application rejects cycles, self-parenting and a parent from a different
project at the request/domain boundary.

### 6.2 Recommended relational shape

~~~text
tenants
  id PK

projects
  id PK
  tenant_id FK NOT NULL → tenants.id

assets
  id PK
  project_id FK NOT NULL → projects.id
  asset_type ENUM-like value: site | factory | area | production_line |
              station | equipment
  name, code, location, status, timestamps

entity_relations
  id PK
  project_id FK NOT NULL → projects.id
  source_type, source_id
  target_type, target_id
  relation_type = contains
~~~

Minimum indexes:

- `projects(tenant_id)`
- `assets(project_id)`
- `assets(project_id, asset_type, code)`
- `entity_relations(project_id, source_type, source_id, relation_type)`
- `entity_relations(project_id, target_type, target_id, relation_type)`

All list queries must start from the authorized tenant/project scope. The
relation constraint is the hierarchy integrity boundary; the Policy remains
the authorization boundary.

---

## 7. MQTT Protocol

Topics are namespaced by project and equipment. Equipment tokens must never be logged and should be stored hashed where possible.

### Telemetry

~~~text
liteboard/{project_id}/equipments/{equipment_id}/telemetry
~~~

~~~json
{
  "message_id": "01JXYZ",
  "ts": 1789491600000,
  "values": {
    "temperature": 64.2,
    "vibration": 1.72,
    "current": 8.3,
    "rpm": 1450
  }
}
~~~

### Heartbeat

~~~text
liteboard/{project_id}/equipments/{equipment_id}/heartbeat
~~~

~~~json
{
  "ts": 1789491600000,
  "firmware": "1.0.3"
}
~~~

Heartbeat updates last_seen_at and equipment state: ONLINE, OFFLINE or UNSTABLE.

### Commands and acknowledgement

~~~text
liteboard/{project_id}/equipments/{equipment_id}/commands
liteboard/{project_id}/equipments/{equipment_id}/commands/ack
~~~

~~~json
{
  "command_id": "cmd-123",
  "type": "SET_SAMPLING_RATE",
  "payload": {"interval_ms": 500}
}
~~~

~~~json
{
  "command_id": "cmd-123",
  "status": "SUCCESS",
  "result": {},
  "executed_at": 1789491600000
}
~~~

Protocol requirements:

- Validate equipment identity, topic ownership, timestamp, JSON and schema.
- Preserve message_id for tracing and idempotency.
- Define MQTT QoS and retained-message behavior.
- Handle reconnects and duplicate messages safely.
- Never log credentials or private data.

---

## 8. Technical Development Phases

### Phase 0 — Foundation and local deployment

Create a reproducible monorepo:

~~~text
liteboard/
├── backend/              # Laravel Control Plane
├── telemetry-service/    # Python MQTT/Data Plane
├── frontend/             # Vue 3
├── simulator/            # Python device simulator
├── infrastructure/       # Docker, Nginx, monitoring
├── docs/
└── docker-compose.yml
~~~

Features:

- Laravel, FastAPI telemetry service and Vue foundations.
- PostgreSQL migrations and Redis connection.
- EMQX/Mosquitto, Docker network and health checks.
- .env.example, structured logging and exception handling.
- GET /health for each application service.

Deliverable:

~~~text
PostgreSQL + Redis + EMQX + Laravel API + Python service + Vue frontend
~~~

runs with docker compose up -d.

### Phase 1 — Identity, hierarchy and equipment

- Login, logout, token refresh and current user.
- Tenant management foundation plus the implemented Project workspace and
  Asset Masterdata backend.
- Asset CRUD for Site, Factory, Area, Production Line, Station and Equipment.
- One `contains` relation with derived `parent_asset_id` API projection.
- Device, MQTT, credentials, firmware, telemetry and command management remain
  future phases.
- Roles: Admin, Engineer, Operator and Viewer.
- Permissions for equipment, schema, rule, alert and command actions.

Example APIs:

~~~text
GET|POST /api/tenants/{tenant}/projects
GET|PATCH|DELETE /api/tenants/{tenant}/projects/{project}
GET|POST /api/tenants/{tenant}/projects/{project}/assets
GET|PATCH|DELETE /api/tenants/{tenant}/projects/{project}/assets/{asset}
~~~

### Phase 2 — MQTT connectivity and ingestion

~~~text
ESP32 → MQTT publish → Broker → Python consumer
      → Authentication → Decode → Processing
~~~

Implement telemetry subscription, equipment authentication, topic authorization, payload decoding, normalization, heartbeat handling, reconnects and online/offline state.

### Phase 3 — Schema-first validation

Implement create/update/version schema APIs, integer/float/string/boolean fields, units, required fields, ranges, schema lookup by profile, validation history and structured errors.

~~~text
MQTT Payload
    ↓
JSON Decode
    ↓
Schema Lookup
    ↓
Type Validation
    ↓
Range Validation
    ↓
Store / Reject with Reason
~~~

Example error:

~~~json
{
  "field": "temperature",
  "expected": "float",
  "received": "HOT",
  "reason": "invalid_type"
}
~~~

### Phase 4 — Telemetry storage and query

Initial record:

~~~text
telemetry:
  id, message_id, equipment_id, metric_key, timestamp,
  numeric_value, string_value, boolean_value, processing_status
~~~

Pipeline:

~~~text
MQTT → Validation → Normalization → Memory Buffer
     → Batch Insert → PostgreSQL / TimescaleDB
~~~

Initial targets:

~~~text
batch_size = 100
flush_interval = 500ms
~~~

Implement query API GET /api/equipments/{equipment}/telemetry with keys, from, to, limit and aggregation filters. Support avg, min, max and count over 1m, 5m, 15m, 1h and 1d intervals.

Indexes:

~~~text
(equipment_id, timestamp)
(equipment_id, metric_key, timestamp)
~~~

Initial retention proposal: raw telemetry 30 days, minute aggregates 6 months and hourly aggregates 2 years. Adjust after storage benchmarks. Add TimescaleDB, partitioning and downsampling only when justified.

### Phase 5 — Realtime monitoring and Live Message Inspector

Implement WebSocket events:

~~~text
equipment.telemetry
equipment.status
alert.created
alert.updated
~~~

Example endpoint:

~~~text
/ws/equipments/{id}/telemetry
~~~

Live Message Inspector is a signature LiteBoard feature. It shows message ID, equipment, topic, safe payload, each processing stage, timing, final status and error reason.

~~~text
MQTT received        ✓
Authentication       ✓
JSON decoding        ✓
Schema validation    ✓
Rule evaluation      ✓
Database storage     ✓
WebSocket broadcast  ✓
~~~

Redact tokens, credentials and sensitive payload fields.

### Phase 6 — Rule and condition engine

Rules are configuration, not arbitrary user code.

~~~json
{
  "name": "High Temperature",
  "conditions": [
    {"metric": "temperature", "operator": ">", "value": 80}
  ],
  "duration_seconds": 30,
  "severity": "WARNING"
}
~~~

V1 operators: >, >=, <, <=, ==, !=, AND and OR.

Implement rule CRUD, enable/disable, profile/equipment assignment, multi-condition evaluation, duration/debounce, trigger history and actions CREATE_ALERT, SEND_NOTIFICATION, PUBLISH_MQTT and WEBHOOK.

### Phase 7 — Alerts, events and webhooks

Alert levels: INFO, WARNING, MAJOR and CRITICAL.

Lifecycle:

~~~text
OPEN → ACKNOWLEDGED → RESOLVED
~~~

Implement creation, active/history views, acknowledge, resolve, automatic recovery where supported, realtime notifications and outbound webhooks with retries and failure history.

Target integrations: EAMO, MES, ERP, notification services and custom backends.

### Phase 8 — Remote device commands / RPC

Commands:

~~~text
RESTART
SET_SAMPLING_RATE
SET_THRESHOLD
REQUEST_STATUS
RELAY_ON
RELAY_OFF
~~~

Lifecycle:

~~~text
PENDING → SENT → ACKNOWLEDGED
                    └── FAILED / TIMEOUT
~~~

Require unique command_id, idempotent device handling, MQTT publisher, ACK/result handler, configurable 10-second timeout, maximum three retries, audit trail and permission checks.

### Phase 9 — Industrial profiles and EAMO demo

Profiles: Motor, Pump, Compressor, Boiler, Conveyor and Gateway.

Demo:

~~~text
ESP32 / Simulator → Sensors → MQTT → LiteBoard
→ Schema Validation → Storage → Rule Engine
→ Abnormal Condition → Webhook → EAMO
→ Maintenance Alert / Work Order
~~~

### Phase 10 — Queue and asynchronous processing

Use Redis queues:

~~~text
telemetry, rules, notifications, commands, webhooks
~~~

Workers:

~~~text
TelemetryWorker, RuleWorker, NotificationWorker,
CommandWorker, WebhookWorker
~~~

Add exponential backoff, dead-letter queues, idempotency, duplicate handling, queue metrics and backpressure. Expand queues after the synchronous end-to-end ingestion path works.

### Phase 11 — Device simulator and load tests

~~~bash
python simulator.py --devices 10 --interval 1000
python simulator.py --devices 1000 --interval 1000
~~~

Simulate temperature, vibration, current, voltage, rpm, pressure, packet loss, disconnects, invalid telemetry and reconnects. Test 100, 500, 1,000 and, when resources allow, 5,000 devices.

### Phase 12 — Observability, performance and production readiness

Prometheus metrics:

~~~text
mqtt_messages_total
mqtt_messages_per_second
telemetry_processing_latency
telemetry_insert_latency
rule_execution_latency
websocket_broadcast_latency
queue_depth
active_devices
failed_messages
~~~

Benchmark messages/sec, connected devices, p50/p95/p99 latency, CPU, RAM and database write latency.

Reliability tests cover Redis outage, slow PostgreSQL, broker disconnect, offline devices, worker crash, duplicate telemetry and lost command ACKs. Anomaly detection, machine health score and fault probability come after this phase.

---

## 9. Deployment Plan

### Local development with Docker Compose

Required services:

~~~text
postgres        PostgreSQL / optional TimescaleDB
redis           Cache and queue
emqx            MQTT broker
api             Laravel Control Plane
telemetry       Python MQTT/Data Plane
frontend        Vue application
nginx           Reverse proxy when needed
prometheus      Metrics
grafana         Dashboards
~~~

Requirements:

- .env.example with safe development defaults.
- Separate database, Redis, MQTT and application secrets.
- Persistent volumes for PostgreSQL/TimescaleDB, EMQX and Grafana.
- Shared internal Docker network and service health checks.
- Internal-only database and Redis ports where possible.
- Documented MQTT credentials and topic permissions.
- No secrets committed to the repository.

Quick start:

~~~bash
git clone <repository>
cd liteboard
cp .env.example .env
docker compose up -d
docker compose ps
~~~

Then run migrations, open the UI, create a project/equipment and publish a sample MQTT message.

### Production deployment on a Linux VPS

Initial production deployment uses Docker Compose on Linux. Kubernetes and multi-region deployment are deferred.

~~~text
Internet
   ↓ HTTPS :443
 Nginx / TLS
   ├── /       → Vue frontend
   ├── /api    → Laravel API
   ├── /ws     → WebSocket gateway
   └── /health → health endpoints

Private Docker network
   ├── Laravel API
   ├── Python telemetry service
   ├── PostgreSQL / TimescaleDB
   ├── Redis
   └── EMQX
~~~

Requirements:

- Supported Linux distribution, Docker Engine and Compose plugin.
- Domain name and TLS certificate at Nginx.
- Firewall exposing only 80/443 and the required public MQTT listener.
- PostgreSQL, Redis and internal service ports not publicly exposed.
- Secrets supplied through environment or secret management.
- Persistent, separately backed-up database and broker volumes.
- Restart policies, resource limits, log rotation and monitoring alerts.
- Migration and rollback procedure.
- Smoke test after deployment: health, login, MQTT publish, storage, WebSocket, alert and command ACK.

Example:

~~~bash
git pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm api php artisan migrate --force
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps
~~~

Exact image tags, domains, ports and backup commands belong in docs/deployment.md once infrastructure files are implemented.

### Backup and recovery

- Schedule PostgreSQL backups and verify restoration periodically.
- Back up application configuration separately from data volumes.
- Define retention for telemetry, aggregates, logs and dead-letter messages.
- Document recovery after PostgreSQL, Redis and broker failure.
- Treat Redis as rebuildable cache/queue state unless persistence is required.

---

## 10. Frontend Screens

~~~text
Dashboard
Projects / Tenants
Production Lines / Equipment
Equipment
 └── Equipment Detail
      ├── Overview
      ├── Telemetry
      ├── Schema
      ├── Commands
      ├── Alerts
      └── Message Inspector
Equipment Profiles
Telemetry Schemas
Rules
Alerts
Webhooks
System Logs
~~~

Avoid a complex dashboard editor initially. Prioritize total devices, online/offline devices, active alerts, messages per second and processing latency.

---

## 11. Testing Strategy

### Unit tests

- Schema and payload validation.
- Message normalization.
- Rule evaluation and duration handling.
- Command status transitions.
- Alert lifecycle.
- Idempotency and duplicate handling.

### Integration tests

~~~text
MQTT → telemetry service → validation → database
~~~

Also cover broker reconnect, queue processing and webhook retry.

### API and frontend tests

- Authentication and authorization.
- Project, factory, production line, equipment, schema, rule, alert and command APIs.
- WebSocket event delivery.
- Critical dashboard flows.

### Load and failure tests

Record actual throughput and p50/p95/p99 latency for 100, 500 and 1,000 devices. Test PostgreSQL slowness, Redis outage, MQTT disconnect, device offline, worker crash, duplicate messages and lost command ACKs.

---

## 12. Benchmark Plan

This section defines progressive load levels for LiteBoard. These are test scenarios, not guaranteed production capacity. Actual results must be recorded for the exact hardware, Docker configuration, payload size, MQTT QoS, worker count and database setup used in each run.

### Benchmark dimensions

Measure the complete path:

~~~text
Simulator → MQTT Broker → Telemetry Service → Validation
          → Buffer / Queue → Database → Rule Engine → WebSocket
~~~

Record at every level:

- Total messages sent, accepted, rejected and failed.
- Throughput in messages per second.
- End-to-end p50, p95 and p99 latency.
- MQTT receive, validation, queue, database and WebSocket latency.
- Connected devices and messages per device.
- Queue depth, retry count, duplicate count and dead-letter count.
- CPU, RAM, database connections, disk I/O and network usage.
- Data-loss or reconciliation result after the test.

### Progressive load levels

| Level | Scenario | Devices | Rate per device | Total rate | Duration | Main objective |
|---|---|---:|---:|---:|---:|---|
| L0 Smoke | Developer smoke test | 10 | 1 msg/s | 10 msg/s | 5 min | Verify the complete path and data correctness. |
| L1 Baseline | Stable baseline | 100 | 1 msg/s | 100 msg/s | 10 min | Establish normal latency and resource usage. |
| L2 Growth | Medium workload | 500 | 1 msg/s | 500 msg/s | 15 min | Evaluate batching, queue depth and database writes. |
| L3 High | High sustained workload | 1,000 | 1 msg/s | 1,000 msg/s | 30 min | Validate worker scaling and memory stability. |
| L4 Stress | Stress and backpressure | 5,000 | 1 msg/s | 5,000 msg/s | 30 min | Find the ingestion limit and observe recovery behavior. |
| L5 Extreme | Capacity ceiling experiment | 10,000 | 1 msg/s | 10,000 msg/s | 30 min | Locate the bottleneck and document the failure mode. |

The simulator should also run burst tests, for example 10 seconds at 10 msg/s per device, to evaluate queue buffering and backpressure. L4 and L5 are capacity experiments and may require dedicated infrastructure; they are not release gates by themselves.

### Pass criteria by level

- L0: all services are healthy, the message trace is complete and stored data reconciles with sent data.
- L1: the system remains stable for the full run, with no unbounded queue growth or memory leak.
- L2: batch insert and queue behavior are measurable; retry and duplicate rates are recorded.
- L3: worker scaling is tested and p95/p99 latency remains within the project target defined for the environment.
- L4: the backpressure threshold, recovery time and degraded behavior are documented.
- L5: the maximum sustainable throughput, bottleneck and controlled failure behavior are documented; a pass/fail SLA is optional.

### Benchmark report format

Every run should produce a report containing:

~~~text
Date / commit:
Environment:
CPU / RAM:
Docker service configuration:
Payload size / MQTT QoS:
Device count / publish rate:
Duration:
Throughput:
p50 / p95 / p99 latency:
CPU / RAM / database latency:
Queue depth / retries / failures:
Data reconciliation:
Bottleneck:
Conclusion and next action:
~~~

Do not publish estimated numbers as benchmark results. Replace measured fields with actual values after running the simulator.

---

## 13. MVP and Release Roadmap

### MVP

~~~text
Tenant / Project / Equipment Registry
MQTT Connectivity
Telemetry Ingestion
Schema Validation
Telemetry Storage
Realtime Dashboard
Online / Offline Status
Live Message Inspector
Simple Rule Engine
Alerts
~~~

### Milestones

~~~text
M1  Foundation and Docker deployment
M2  Projects, hierarchy and equipment
M3  MQTT ingestion and heartbeat
M4  Telemetry storage and query API
M5  Schema-first validation
M6  Realtime telemetry
M7  Live Message Inspector
M8  Rule Engine
M9  Alerts and lifecycle
M10 Webhooks
M11 Device Commands and ACK
M12 Industrial profiles and EAMO demo
M13 Queue and async workers
M14 Simulator, performance and observability
M15 Production deployment and open-source polish
~~~

### Version roadmap

~~~text
v0.1  Foundation, Docker, authentication, projects and equipment
v0.2  MQTT, telemetry ingestion and heartbeat
v0.3  Schema validation, storage, query API and realtime dashboard
v0.4  Message Inspector, rules and alerts
v0.5  Webhooks, remote commands, ACK and retry
v0.6  Device simulator and load testing
v0.7  Redis queues, async workers and dead-letter handling
v0.8  Prometheus, Grafana and structured logging
v0.9  TimescaleDB, retention, partitioning and performance work
v1.0  Stable release, deployment guide, API docs and EAMO demo
v1.1+ Anomaly detection, health score, SDKs and horizontal scaling
~~~

### Successful V1

A developer can start LiteBoard with Docker, create a project/device, define a schema, connect an ESP32 through MQTT, publish validated telemetry, query it through REST, watch it through WebSocket, inspect processing, create rules and alerts, send webhooks and send commands with ACKs.

---

## 14. Repository and Documentation

~~~text
liteboard/
├── backend/
├── telemetry-service/
├── frontend/
├── simulator/
├── infrastructure/
│   ├── nginx/
│   ├── prometheus/
│   └── grafana/
├── docs/
├── docker-compose.yml
├── docker-compose.prod.yml
└── README.md
~~~

Recommended docs:

~~~text
docs/
├── plan.md
├── architecture.md
├── mqtt-protocol.md
├── database-design.md
├── telemetry-pipeline.md
├── schema-validation.md
├── rule-engine.md
├── device-command.md
├── performance-testing.md
└── deployment.md
~~~

README quick start should present:

~~~text
ESP32 → MQTT → LiteBoard → Your Application
~~~

Include API/MQTT documentation, sample ESP32 client, Python simulator, EAMO integration example, contribution guidance and measured benchmark results.

---

## 15. Technical Outcomes and Long-Term Direction

LiteBoard demonstrates Laravel architecture, async Python, FastAPI, MQTT, WebSocket, PostgreSQL, time-series storage, queues, retries, idempotency, Docker, Linux, Nginx, Prometheus, Grafana, backups and failure testing.

Future features may include gateway support, device groups, device shadow, OTA updates, advanced time-window rules, anomaly detection, predictive maintenance, SDKs, plugins, tenant isolation and horizontal scaling.

These features are deferred, not deleted. They should be considered only after ingestion, schema validation, observability, reliability and deployment are stable.

LiteBoard should remain:

> **A lightweight open-source IoT backend focused on MQTT, structured telemetry, observability and industrial integration.**

The goal is to build a platform that is smaller, simpler, easier to understand, easier to debug and strong for industrial IoT integrations.

---

## 16. Production Readiness Checklist

The development plan is not production-ready only because the platform starts with Docker Compose. Production release requires the following controls to be implemented, tested and documented.

### Security

- TLS for MQTT, preferably on port 8883, and TLS for HTTP/WebSocket traffic.
- Broker ACL scoped to the project and equipment; equipment credentials can be revoked and rotated.
- Secrets supplied through environment or secret management and never committed to Git.
- API rate limiting, MQTT connection/message limits and maximum payload size.
- Webhook SSRF protection, URL validation and outbound network restrictions.
- Audit logs for authentication, permission changes, equipment credentials, commands, rules and alerts.
- Dependency, container image, SBOM and vulnerability scanning in CI.
- Security headers, strict CORS policy and production-safe error responses.

### Reliability and recovery

- PostgreSQL backup, restore verification and Point-in-Time Recovery procedure.
- Replication or a documented recovery strategy when database high availability is required.
- MQTT broker persistence/HA strategy and reconnect behavior.
- Redis failure strategy; distinguish rebuildable cache state from durable queue state.
- Outbox/Inbox pattern for important events and webhook delivery.
- Idempotent ingestion, duplicate detection and safe command execution.
- Explicit handling for late, out-of-order and clock-skewed telemetry.
- Graceful shutdown, readiness/liveness probes and worker draining.
- Migration rollback procedure and disaster recovery runbook.

### SLO and observability

Define targets per deployment environment instead of making unmeasured promises. Initial examples:

~~~text
Telemetry ingestion success rate  > 99.9%
p95 processing latency            < 500 ms
API availability                  > 99.9%
~~

Add correlation IDs from MQTT message through storage, rule evaluation, webhook and WebSocket delivery. Configure alerts for queue growth, database latency, broker disconnects, error rate, disk usage, certificate expiry and worker health. Keep log retention rules and an incident runbook for each critical alert.

### Data management

- Time-based partitioning or TimescaleDB hypertables for telemetry where justified.
- Automated retention, aggregation and downsampling jobs.
- Schema compatibility/version policy for devices and firmware.
- Data quality checks for duplicates, late data and missing intervals.
- Tenant isolation and authorization tests when multi-tenancy is enabled.
- Encryption at rest and controlled access for sensitive application data.

### Delivery and operations

- CI pipeline covering unit, integration, API, migration, lint, type, security and image checks.
- Staging environment that matches production topology.
- Immutable versioned images and versioned configuration.
- Automated migration, smoke test and rollback steps.
- Blue-green or canary deployment when downtime is unacceptable.
- Resource limits, capacity plan, log rotation and host-level monitoring.
- Patch process for the OS, runtime, dependencies, broker and database.
- Documented ownership, escalation path, incident response and maintenance windows.

### Production load validation

Before go-live, run the Benchmark Plan at the highest intended level and also run:

- A 24-hour soak test to find memory leaks and disk growth.
- Burst traffic and recovery tests.
- Device connection/disconnection churn.
- Large-payload and invalid-payload tests.
- PostgreSQL slow/failure, Redis outage and broker restart tests.
- Worker termination during processing.
- Webhook timeout and lost command ACK tests.

Record throughput, p50/p95/p99 latency, error rate, queue behavior, resource headroom and data reconciliation. Do not use estimated values as production capacity.

### Go-live gate

LiteBoard should not be called production-ready until security review, backup restore, failure testing, benchmark/soak results, monitoring alerts, rollback and operational runbooks have all been completed and reviewed.
