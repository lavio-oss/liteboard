# LiteBoard — Development, Technical & Deployment Plan

## 1. Product Identity

LiteBoard is an open-source, multi-tenant IoT platform between MQTT brokers and business applications such as EAMO and MES. It focuses on real-time telemetry, device management, and distributed backend architecture.

> **LiteBoard is an open-source multi-tenant IoT platform focused on real-time telemetry, device management, and distributed backend architecture.**

LiteBoard may overlap with parts of ThingsBoard, but it is not intended to reproduce the whole product. Its purpose is to build a small set of core IoT capabilities deeply and make the backend, messaging, storage, failure, and scaling behavior understandable and measurable.

### Main architecture

~~~text
ESP32 / PLC / Edge Device
          │ MQTT
          ▼
   EMQX or Mosquitto
          │ MQTT subscription
          ▼
      LiteBoard
      ├── Device Management
      ├── Telemetry Ingestion
      ├── Queue / Processing
      ├── Device State
      ├── Time-series Storage
      ├── Rules / Alerts
      ├── Command / RPC
      ├── REST / WebSocket
      └── Multi-tenancy / Observability
          │
          ▼
   Dashboard / EAMO / MES
~~~

LiteBoard uses an existing MQTT broker. It does not implement MQTT protocol handling or broker clustering itself.

### Product goals

LiteBoard should let a developer:

- Create tenant-scoped projects, devices, credentials, profiles, and telemetry schemas.
- Connect devices through MQTT and inspect an authenticated, validated ingestion path.
- Query stored time-series data through REST and receive authorized real-time events.
- Define bounded rules, manage alerts, and send commands with acknowledgements.
- Operate and diagnose the system under duplicates, disconnects, slow dependencies, restarts, and growing load.
- Integrate downstream applications through stable APIs and versioned events.

The project is also a system engineering lab. A feature is not complete merely because its happy path works: each milestone must expose its failure behavior, measurements, and scaling limit.

---

## 2. Scope and Non-Goals

### Core modules

- Multi-tenancy, projects, roles, and tenant-scoped authorization.
- Device management, credential lifecycle, device profiles, and Equipment association.
- MQTT telemetry ingestion, envelope validation, normalization, and device state.
- Time-series storage, retention, query, and aggregation.
- Rule evaluation, alert lifecycle, and event delivery.
- Command / RPC with acknowledgement, timeout, retry, and audit history.
- REST and tenant-authorized WebSocket APIs.
- Logs, metrics, tracing, simulator, load tests, and failure testing.

### Non-goals for the initial releases

LiteBoard is not initially a:

- Custom MQTT broker or broker-clustering implementation.
- Full ThingsBoard replacement or general-purpose dashboard builder.
- SCADA, MES, ERP, maintenance-management, or arbitrary workflow system.
- Multi-protocol gateway platform; MQTT is the first device protocol.
- Arbitrary user-code execution platform or advanced ML / predictive-maintenance system.
- Kubernetes, multi-region, or massive-scale benchmark exercise before the single-node path is correct.

EMQX and Mosquitto provide broker capabilities. LiteBoard owns application-level devices, telemetry contracts, tenant isolation, state, rules, APIs, and commands.

---

## 3. Engineering Principles

### Small vertical slices

Build one end-to-end path at a time. Start with an authenticated device publishing one valid message, then add storage, query, and visibility before broadening the feature set.

### Failure-first engineering

For every milestone, ask where it breaks under concurrency, duplicate delivery, out-of-order data, retries, queue saturation, broker or database interruption, worker restart, and horizontal scale. Record the observed result and the next engineering action.

### Tenant isolation by construction

Tenant and project ownership must be preserved in authorization, broker topic permissions, validation, queries, cache keys, queue jobs, events, WebSocket subscriptions, commands, and tests. A client-supplied identifier is never authority by itself.

### Schema-first and API-first

Device messages use explicit versioned envelopes and schema definitions. REST, MQTT, and WebSocket contracts are documented so a dashboard or external application can consume LiteBoard without depending on internal service implementation.

### Measure before optimizing

Batching, indexes, retention, Redis, time-series extensions, partitioning, extra workers, and broker changes must respond to measured bottlenecks. Benchmark output records the exact commit, configuration, payload, hardware, and workload; targets are not presented as achieved results.

### Learning outcomes

Milestones should deliberately develop backend architecture, PostgreSQL/time-series design, Redis and messaging, event-driven processing, distributed-systems failure handling, Linux, Docker, networking, monitoring, and performance testing.

---

## 4. Technical Baseline

LiteBoard separates control-plane changes from the high-throughput data path.

### Control Plane

- Laravel REST API for authentication, tenants, projects, Devices, profiles, schemas, rules, alerts, and commands.
- Policies and route-bound ownership checks for all tenant-owned resources.
- PostgreSQL for control-plane records and initial telemetry storage.
- API and event contracts consumed by the web application and external systems.

### Data Plane

- Python / FastAPI service for health and readiness endpoints.
- Async MQTT client consuming from the configured external broker.
- Authentication context, topic authorization, decoding, schema validation, normalization, idempotency, buffering, and telemetry ingestion.
- Explicit processing outcomes and metrics; do not treat successful subscription or log output as successful ingestion.

### Supporting services and broker profiles

- Mosquitto is the default local-development broker.
- EMQX is an alternative broker profile for integration and scale experiments. Verify the exact release license and deployment terms before recommending it for a distributable production setup.
- Redis supports cache, transient device state, and asynchronous work where justified; durable business events must not depend on an unexamined cache-only queue.
- PostgreSQL is the starting store. Add TimescaleDB, partitioning, or downsampling only after workload measurements justify them.
- Docker Compose provides local services. Prometheus and Grafana support measured observability as the project matures.

---

## 5. High-Level Architecture

~~~text
                    ┌──────────────────────────┐
                    │ Web UI / EAMO / MES      │
                    └────────────┬─────────────┘
                         REST / WebSocket / events
                                 │
                    ┌────────────▼─────────────┐
                    │ Laravel Control Plane    │
                    │ Tenant / Project / Device│
                    │ Profiles / Schemas       │
                    │ Rules / Alerts / Commands│
                    └──────┬───────────┬───────┘
                           │           │
                    PostgreSQL        Redis
                    control +         cache / queue /
                    telemetry         transient state
                           ▲           ▲
                           │ ingest    │ async processing
                    ┌──────┴───────────┴───────┐
                    │ Python MQTT Data Plane   │
                    │ Decode / Validate /       │
                    │ Normalize / Buffer       │
                    └────────────▲─────────────┘
                                 │ MQTT subscribe
                    ┌────────────┴─────────────┐
                    │ EMQX or Mosquitto        │
                    │ External MQTT broker    │
                    └────────────▲─────────────┘
                                 │ MQTT publish
                    ┌────────────┴─────────────┐
                    │ ESP32 / PLC / Edge Device│
                    └──────────────────────────┘
~~~

### Message processing path

~~~text
MQTT received
  → broker-authenticated connection and topic permission
  → topic / Device / project ownership check
  → if a Profile Version is assigned: decode, validate the envelope/schema, and normalize
  → if no Profile Version is assigned: accept flat metrics or `{values: {...}}`, validate the basic payload contract, and supply missing message metadata
  → idempotency check and bounded buffering
  → telemetry persistence
  → device state / rules / alerts
  → REST query and authorized WebSocket delivery
~~~

Each stage must have a measurable outcome and a defined failure policy. Business application delivery is retryable and idempotent; it must not block MQTT receipt indefinitely.

## 6. Domain Model

LiteBoard is a multi-tenant Control Plane and Data Plane platform. Tenant is the isolation boundary; Project is the authorization and ownership boundary for Devices and Assets.

### 6.0 Current implementation status — 2026-10-07

This is a repository/commit review, not a production-readiness claim. A committed implementation is not considered runtime-accepted until its phase gate has recorded the required evidence.

Implemented in the control plane and device flows:

- Tenant/project membership and authorization, plus the project-owned Asset hierarchy.
- Tenant/project-scoped Device management, Equipment association, per-Device Access Tokens, broker authentication/ACLs, and the device UI.
- Project-scoped provisioning profiles, bootstrap receipts, approval/rejection and audit history, plus a shared `max_devices_quota` for manual creation and provisioning.
- Local Mosquitto TLS on port `8883`, with CA verification in the simulator and telemetry service. This does not complete production certificate rotation or production-topology acceptance.

Current Data Plane behavior in `telemetry-service/app/main.py`:

- Connects to the broker over TLS and subscribes to telemetry and heartbeat topics.
- Resolves telemetry to an active, approved Device under an active Project and Tenant; updates presence even when that Device's telemetry is rejected.
- For a Device with an assigned Profile Version, requires a bounded MQTT v5 envelope, validates against that version, and records a durable sample or rejection/dedup outcome in PostgreSQL. A Device without an assigned profile accepts bounded flat or wrapped scalar telemetry, supplies missing message metadata, persists the sample with a null Profile Version, and skips only profile-schema validation.
- Uses a persistent MQTT session and returns subscriber PUBACK success only after the PostgreSQL transaction commits. REST history/latest remains out of scope for IT-3.

IT-4 first slice (2026-10-13): tenant-authorized REST latest/history/aggregates and message trace in Laravel, ticket-authorized WebSocket fan-out after commit in the telemetry service, per-stage processing timestamps, and a Device detail page. Pilot latency percentiles are not yet measured.

Still open: measured ingest metrics and L0 reconciliation, IT-4 pilot latency baseline, offline-state transitions, and rules/alerts/commands. Device and provisioning data are tenant-owned through Project; each next data-plane path must derive and retain that organization context rather than trust topic or payload IDs alone.

### Core entity distinctions

- Tenant: isolation boundary for users, projects, and resources below them.
- Project: workspace belonging to exactly one tenant.
- Asset: tenant-owned master-data node in one project.
- Equipment: an Asset type representing an operational machine or component.
- Device: a technical identity that authenticates to MQTT and sends telemetry or receives commands. A Device belongs to one project and may be associated with an Equipment Asset.
- Device Profile: shared protocol/schema defaults assigned to Devices.
- Telemetry Schema: versioned field definitions, types, units, ranges, and required state.
- Telemetry: normalized, time-stamped Device measurements with message identity and processing status.
- Rule / Alert / Command: application-level resources scoped through their Device, Equipment, Project, and Tenant.

A Device credential must never be represented by an Equipment record. Authorization must resolve the authenticated Device to its project and permitted topics before trusting a topic or payload identifier.

### 6.1 Asset hierarchy database contract

The assets table requires project_id, and asset codes are unique within project_id + asset_type + code. An asset may be a root or have one parent. The source of truth for hierarchy is entity_relations:

~~~text
entity_relations
  project_id    FK NOT NULL → projects.id
  source_type   = asset
  source_id     → parent asset identity
  target_type   = asset
  target_id     → child asset identity
  relation_type = contains
~~~

This phase accepts only asset contains asset. A unique constraint on project_id + target_type + target_id + relation_type allows at most one incoming contains relation per asset. Source, target, and project indexes support hierarchy traversal and scoped list queries.

### 6.2 Recommended relational shape

Keep control-plane identity and high-volume telemetry concerns distinct. Recommended ownership:

~~~text
Tenant
└── Project
    ├── Asset graph
    │   └── Equipment Asset
    └── Device registry
        ├── credential / status / last_seen_at
        └── optional association to Equipment Asset
~~~

Telemetry records and processing history must resolve to a project through the Device. Application queries must begin from authorized tenant/project scope. Never assume that a globally unique ID by itself grants access.

## 7. MQTT Protocol

The broker authenticates the MQTT connection or delegates authentication to the configured LiteBoard integration. LiteBoard authorizes each Device against its project and allowed topic namespace. A topic or payload ID alone is not proof of ownership.

### Topic namespace

~~~text
liteboard/{project_id}/devices/{device_id}/telemetry
liteboard/{project_id}/devices/{device_id}/heartbeat
liteboard/{project_id}/devices/{device_id}/commands
liteboard/{project_id}/devices/{device_id}/commands/ack
~~~

Use project and Device IDs as routing context, but derive authority from the authenticated credential and server-side Device record. Store credentials safely, support revocation/rotation, and never log tokens.

### Telemetry envelope

~~~json
{
  "message_id": "01JXYZ",
  "occurred_at": "2026-09-25T10:00:00Z",
  "values": {
    "temperature": 64.2,
    "vibration": 1.72,
    "current": 8.3,
    "rpm": 1450
  }
}
~~~

The Data Plane adds received_at and records processing status. The message ID is the idempotency key for QoS redelivery. Device schema version and data quality metadata are introduced with the versioned schema contract.

### Heartbeat

~~~json
{
  "occurred_at": "2026-09-25T10:00:00Z",
  "firmware": "1.0.3"
}
~~~

Heartbeat updates last_seen_at and Device state: ONLINE, OFFLINE, or UNSTABLE. State transitions use server-side timeouts and emit tenant-scoped events.

### Commands and acknowledgement

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
  "executed_at": "2026-09-25T10:00:00Z"
}
~~~

Command delivery is at-least-once unless a stronger guarantee is proven. Device handling must be idempotent by command_id; LiteBoard records pending, sent, acknowledged, failed, and timed-out states.

### Protocol invariants

- Validate connection identity, topic ownership, envelope, timestamp, payload size, and schema.
- Keep occurred_at (device event time) separate from received_at (server arrival time).
- Handle duplicate, late, out-of-order, malformed, and schema-invalid messages with explicit outcomes.
- Define QoS and retained-message behavior for telemetry, heartbeat, commands, and acknowledgements.
- Redact credentials and sensitive fields from logs, traces, message inspection, and error responses.
- Carry tenant/project context through persistence, queues, rules, WebSocket events, and downstream delivery.

## 8. Technical Development Phases

Every phase is a vertical slice with an observable happy path, at least one failure or load scenario, tenant-isolation coverage where applicable, and recorded evidence. Do not advance by counting features alone. The gate is the measured result and a documented decision about the next bottleneck.

### Phase 0 — Establish the real baseline

- Reconcile the repository map, Docker Compose services, environment examples, service health checks, and actual application paths.
- Keep Mosquitto as the local broker. Add an EMQX profile only for broker compatibility and scale exercises; verify version and license before production recommendations.
- Record the working local workflow and known blockers. Do not call the platform end-to-end until simulator data is persisted and queryable.

Status: Compose now points the frontend service at the existing `web/` directory. The earlier `frontend/` versus `web/` mismatch is resolved in configuration. The Phase 0 gate remains open until a reproducible startup and service-health run is recorded.

### Phase 1 — Tenant-scoped Device Management

Status: the Device management and provisioning implementation is present, with backend feature tests. Keep the failure and tenant-isolation acceptance evidence distinct from the existence of the implementation.

- Keep Tenant → Project → Asset hierarchy as the existing master-data foundation.
- Add Device identity, profile, credential issue/revocation, status, and optional association to an Equipment Asset.
- Provide REST operations nested under the authorized tenant/project boundary. Enforce tenant/project ownership on Device list, detail, credential, and association operations.

Planned resource shape:

~~~text
GET|POST /api/tenants/{tenant}/projects/{project}/devices
GET|PATCH|DELETE /api/tenants/{tenant}/projects/{project}/devices/{device}
~~~
- Define broker authentication and per-Device topic permissions before accepting production-like device traffic.

Failure exercise: guessed Device IDs, wrong-project topic, revoked credential, credential rotation, and cross-tenant API access.

Gate: an authorized Device can be created and assigned a topic scope; unauthorized identities cannot publish or access Device data. Record auth failures and connection state.

### Phase 2 — Secure MQTT ingestion L0

Status: the durable ingest path is implemented under industrial-telemetry IT-3; live outage/recovery and five-minute L0 acceptance remain open.

~~~text
Simulator → MQTT broker → Python consumer → authenticate / authorize
          → decode / envelope validation → idempotent durable ingest → PostgreSQL
~~~

- Subscribe to Device topic namespaces and bind topic identity to the authenticated Device.
- Require message_id, schema_version, occurred_at, and values for Devices with an assigned profile. Devices without a profile may send flat metric key/value pairs or `{values: {...}}`; persist valid scalar metrics with server-generated message metadata when omitted and skip only profile-schema validation. Keep `received_at` and a traceable processing result.
- Define payload-size limits, QoS and retained-message behavior. Redact credentials and avoid logging unrestricted payloads.
- Persist accepted messages, dedup identities, and outcomes in one PostgreSQL transaction; keep the Device → Project → Tenant scope at every write.
- Handle reconnect and QoS 1 redelivery with the persistent MQTT session and application-level idempotency.

Failure exercise: malformed JSON, wrong tenant or Device, duplicate message_id, revoked credential, broker restart, and consumer reconnect.

L0 gate: 10 simulated devices at 1 message/second each for 5 minutes; reconcile sent, accepted, rejected, skipped, persisted, and duplicate messages; bounded memory/queue; no unexplained loss; record end-to-end p50/p95/p99 and recovery behavior. This is a development gate, not a production capacity claim.

### Phase 3 — Schema validation and durable time-series ingestion

- Device Profile schema validation and structured rejection reasons are implemented under IT-2. Devices without an assigned Profile Version persist basic telemetry after scalar-payload validation and skip only profile-schema validation.
- Persist valid measurements with Device, Project, nullable Profile Version, message identity, event time, receive time, and processing status; record rejected, duplicate, and conflict outcomes without raw payloads.
- Keep PostgreSQL as the initial store. Do not add automatic deletion, TimescaleDB, partitioning, downsampling, or batching before measured workload and retention policy justify them.
- Move tenant-authorized REST history/latest to IT-4 so ingestion durability can be verified before query/API work.

Failure exercise: invalid type/range, schema version mismatch, duplicate batch, late/out-of-order timestamps, PostgreSQL slowdown/outage, and process restart during buffered writes.

Gate: accepted data reconciles with the sender; duplicates do not create duplicate logical samples; rejected input has a reason; p50/p95/p99 write latency, in-flight/queued work, memory, database latency, and recovery are measured.

### Phase 4 — Device state, realtime API, and message trace

- Compute ONLINE/OFFLINE/UNSTABLE from heartbeat and server-side timeout policy.
- Expose tenant-authorized REST latest/history reads and WebSocket events for telemetry, state, and later alerts.
  Planned route: GET /api/tenants/{tenant}/projects/{project}/devices/{device}/telemetry.
  Scope WebSocket subscriptions under the authenticated tenant/project and Device; a suggested path is /ws/tenants/{tenant}/projects/{project}/devices/{device}.
- Implement a bounded Message Inspector trace across receive, auth, decode, validation, persistence, rule evaluation, and broadcast.
- Redact credentials and sensitive values; never let a client select a tenant by an untrusted header or topic alone.

Failure exercise: reconnect churn, stale heartbeat, WebSocket reconnect, slow subscriber, Redis interruption, and attempts to subscribe across tenant/project scope.

Gate: trace correlation from message_id to stored telemetry and event delivery; bounded subscriber/queue behavior; tenant isolation verified for REST and WebSocket.

### Phase 5 — Rules, alerts, and outbound events

- Implement a constrained configuration-based rule language; do not execute arbitrary tenant code.
- Start with scalar comparisons and bounded AND/OR conditions, then duration/debounce, recovery, and trigger history.
- Define alert lifecycle and retryable outbound webhooks/events with idempotency and delivery history.
- Keep rule ownership and evaluation scoped to the Device/Profile's project.

Failure exercise: duplicate evaluation, out-of-order samples, rule update during processing, webhook timeout/5xx, retry exhaustion, and tenant-crossing references.

Gate: one deterministic threshold-to-alert-to-webhook scenario has auditable history, bounded retries, duplicate protection, and latency/error metrics.

### Phase 6 — Commands and RPC

- Dispatch authorized commands through the broker to a Device-specific command topic.
  Planned API: POST /api/tenants/{tenant}/projects/{project}/devices/{device}/commands.
- Track command_id through pending, sent, acknowledged, failed, and timed-out states.
- Define timeout, retry limits, audit history, and safe handling of late or duplicate acknowledgements.
- Command permissions are checked for the acting user and target Device's tenant/project.

Failure exercise: offline Device, lost ACK, duplicate command delivery, duplicate/late ACK, worker restart, and unauthorized command attempt.

Gate: simulator executes a command idempotently and LiteBoard reaches a terminal status without falsely reporting success.

### Phase 7 — Queues, backpressure, and horizontal scaling

- Introduce asynchronous queues only where the measured L0/L1 path needs them.
- Specify bounded buffering, admission/backpressure behavior, retry policy, dead-letter visibility, idempotency boundaries, and graceful worker shutdown.
- Run multiple Data Plane workers and verify consumer coordination, duplicate delivery, ordering guarantees, and database connection limits.
- Distinguish rebuildable cache/state from durable telemetry and business-event delivery.

Failure exercise: queue saturation, Redis outage, worker kill during processing, PostgreSQL slow writes, and concurrent workers processing the same message.

Gate: no unbounded memory growth; recovery time and accepted/lost/duplicated counts are measured; no cross-tenant work leakage; throughput and p95/p99 are reported for the tested topology.

### Phase 8 — EAMO/MES integration

- Publish a versioned event contract for downstream applications and a receiver example for EAMO/MES.
- Include source Device, optional Equipment Asset, tenant/project context, condition or alert identity, severity, event time, evidence reference, and idempotency key.
- Use an outbox/retryable delivery path for important events; downstream applications own their business workflow and maintenance records.
- Keep LiteBoard APIs usable by other clients; do not make EAMO the only supported consumer.

Failure exercise: receiver timeout, duplicate delivery, schema/version mismatch, credential rotation, and downstream outage followed by recovery.

Gate: an end-to-end event is delivered once logically despite retries, is traceable to telemetry/rule origin, and cannot cross tenant boundaries.

### Phase 9 — Progressive benchmark and release hardening

- Run L0 and L1 as routine gates. Run larger levels only after the prior level is stable and the environment can support the experiment.
- Profile CPU, memory, broker connections, queue depth, database writes/indexes, network, retries, and end-to-end latency.
- Document bottlenecks and choose one measured optimization per iteration.
- Add retention/backup/restore, TLS, credential rotation, rate limits, dependency/image checks, deployment and incident procedures before a production-ready claim.

Failure exercise: soak, burst, broker restart, PostgreSQL/Redis failure, disk pressure, connection churn, and full restore.

Gate: report exact environment and commit, workload, reconciliation, latency percentiles, resource use, failure behavior, recovery time, and remaining limits. High-load experiments are capacity discovery, not an MVP promise.

## 9. Deployment Plan

### Local development with Docker Compose

Local profile:

~~~text
PostgreSQL
Redis
Mosquitto
Laravel Control Plane
Python Data Plane
Web application
Simulator (optional tools profile)
~~~

- Mosquitto is the local default. Keep the broker behind the Compose network where possible; anonymous access is a development-only setting and must not be presented as secure.
- EMQX is an optional broker profile for protocol compatibility and scale exercises. Pin an explicit version and verify current license/deployment conditions before including it in a distributable production topology.
- Keep application, database, Redis, and broker secrets separate. Use persistent volumes for state that must survive container restarts.
- Every service has meaningful health/readiness checks. Broker readiness does not prove telemetry has been ingested or stored.
- Record the real local startup workflow only after checking it against the repository and Compose paths.
- For a local ESP32 enrollment smoke test, HTTP is available only when Laravel runs with `APP_ENV=local` and `DEVICE_PROVISIONING_ALLOW_INSECURE_HTTP=true`. The simulator requires HTTPS. Use an HTTPS staging endpoint to verify certificate handling; see the [Access Token provisioning flow](./spec/devices/current/devices_access_token.md#provisioning-device-flow).

### Production deployment on a Linux host

- Publish HTTP/WebSocket through a TLS reverse proxy. Use a production domain and trusted certificate, forward the original `Host`, client address and HTTPS scheme, and set Laravel `TRUSTED_PROXIES` only to the proxy addresses as seen by Laravel.
- Point device provisioning URLs at the production HTTPS hostname and provision clients with the CA trust anchor for its certificate chain. Update the simulator CA file, `web/public/provisioning/ca.crt` download, and ESP32 CA header for the deployed endpoint; distribute only public certificates, never CA private keys.
- Keep Laravel's upstream private to the proxy/network. Configure MQTT TLS and its certificate on Mosquitto independently; changing the HTTP reverse proxy does not move MQTT listener `8883`.
- Do not expose PostgreSQL, Redis, or internal service ports publicly.
- Scope broker credentials and ACLs to each Device's project/topic permissions.
- Define resource limits, log rotation, monitoring, backup schedule, restore procedure, upgrade strategy, and operational ownership.
- Treat broker clustering and database HA as measured deployment needs, not MVP prerequisites.
- Document the chosen broker version and license review. EMQX is not an unqualified default for commercial redistribution or hosting.

### Backup and recovery

- Back up PostgreSQL and verify restoration periodically.
- Back up application configuration separately from data volumes.
- Define retention for telemetry, aggregates, logs, and dead-letter records.
- Document recovery after PostgreSQL, Redis, and broker failure.
- Treat Redis as rebuildable cache/transient state unless a selected durable queue configuration is verified.
- Use forward-only schema changes; correct a deployed schema with a later compensating migration rather than rollback.

## 10. Web Application Screens

Keep the web application operational and focused. It is a client of the same REST and WebSocket contracts exposed to other applications.

~~~text
Tenant / Project
  ├── Assets / Equipment
  └── Devices
      └── Device Detail
          ├── Overview / State
          ├── Telemetry
          ├── Schema / Profile
          ├── Commands
          ├── Alerts
          └── Message Inspector
Rules
Alerts / Event History
System Health / Processing Metrics
~~~

Prioritize project-scoped device status, telemetry trends, active alerts, message processing stages, queue depth, and latency. Do not build a general-purpose dashboard editor in the initial releases.

## 11. Testing Strategy

Every milestone includes unit and integration coverage for its new behavior, at least one failure case, and tenant-isolation assertions for tenant-owned data. A passing service health check or console log is not an ingestion acceptance test.

### Unit tests

- Envelope, timestamp, schema, and normalization behavior.
- Rule evaluation, state transitions, and alert lifecycle.
- Command state transitions, idempotency, and retry policy.
- Tenant/project scope resolution and event serialization.

### Integration and contract tests

~~~text
simulator → broker → Data Plane → validation → durable telemetry
          → REST query / WebSocket / rule event / downstream delivery
~~~

Cover broker authentication/ACL, wrong-project topic, invalid/revoked Device, malformed or oversized payload, duplicate message_id, late/out-of-order timestamps, reconnect, and persistence reconciliation. Verify MQTT, REST, and WebSocket contracts with representative clients.

### Failure and tenant-isolation tests

- Broker disconnect/restart, Redis outage, PostgreSQL slowness/outage, queue saturation, and worker termination during processing.
- Duplicate or delayed telemetry and command acknowledgements.
- Retry exhaustion and successful recovery after downstream service returns.
- Cross-tenant/project API query, cache/job/event/WebSocket subscription, Device association, and command attempts.
- Confirm secrets and restricted payload fields do not appear in logs or message traces.

### Load and recovery tests

Run progressive load levels only after L0 acceptance. Record actual throughput, p50/p95/p99 latency, memory, database load, queue depth, retries, loss/duplicate reconciliation, and recovery time for the exact environment.

## 12. Benchmark Plan

Benchmark results describe only the measured commit and environment. Device counts and rates below are test scenarios, not capacity promises. L0 is the required end-to-end development gate; higher levels are run only when resources and prior results justify them.

### Benchmark dimensions

Measure the full implemented path:

~~~text
Simulator → MQTT Broker → Data Plane → Validation
          → Buffer / Queue → Database → Rules → WebSocket
~~~

Record messages sent, accepted, rejected, failed, duplicated, and reconciled; throughput; end-to-end and per-stage p50/p95/p99 latency; connected devices; queue depth; retries; dead letters; CPU/RAM; database connections, disk I/O, and network; and recovery after injected faults.

### Progressive load scenarios

| Level | Devices | Rate per device | Aggregate rate | Duration | Purpose |
|---|---:|---:|---:|---:|---|
| L0 | 10 | 1 msg/s | 10 msg/s | 5 min | Verify end-to-end correctness, bounded buffering, reconnect, and reconciliation. |
| L1 | 100 | 1 msg/s | 100 msg/s | 10 min | Establish a stable baseline. |
| L2 | 500 | 1 msg/s | 500 msg/s | 15 min | Measure batching, queue growth, and database writes. |
| L3 | 1,000 | 1 msg/s | 1,000 msg/s | 30 min | Exercise worker scaling and memory stability. |
| L4 | 5,000 | 1 msg/s | 5,000 msg/s | 30 min | Capacity experiment for backpressure and recovery. |
| L5 | 10,000 | 1 msg/s | 10,000 msg/s | 30 min | Optional capacity-ceiling experiment on suitable infrastructure. |

Also run burst tests, for example 10 seconds at 10 messages/second per device, and reconnect churn. L4 and L5 are not MVP or release gates.

### Pass criteria

- L0: complete processing trace, no unexplained loss, persisted data reconciles with accepted input, bounded queue/memory, reconnect succeeds, and all timing/resource metrics are recorded.
- L1: stable for the run with no unbounded queue growth or continuing memory increase.
- L2/L3: batching and worker scaling behavior are measured; latency is assessed against an environment-specific target.
- L4/L5: document sustainable throughput, backpressure threshold, controlled degraded behavior, bottleneck, and recovery; do not claim a generic pass/fail SLA.

### Benchmark report

~~~text
Date / commit:
Environment / broker version:
CPU / RAM / Docker configuration:
Payload size / MQTT QoS:
Device count / publish rate / duration:
Messages sent / accepted / rejected / failed / duplicate:
Throughput:
End-to-end and per-stage p50 / p95 / p99:
CPU / RAM / database latency / queue depth:
Fault injected / recovery time:
Data reconciliation:
Bottleneck / next measured change:
~~~

Never publish estimated numbers as benchmark results.

## 13. MVP and Release Roadmap

### MVP acceptance

A developer can start LiteBoard, create a tenant-scoped project and Device, issue/revoke credentials, publish authenticated schema-valid MQTT telemetry through Mosquitto, persist and query it, inspect its processing trace, receive authorized WebSocket updates, trigger a simple rule/alert, and complete a command/ACK simulator path.

The MVP also passes L0 for the implemented end-to-end path, including bounded buffering, reconnect, tenant isolation, and message reconciliation. It does not claim production-scale capacity.

### Milestones

~~~text
M1  Repository/runtime baseline and reproducible local services
M2  Tenant-scoped Device registry, credentials, and Equipment association
M3  Secure MQTT ingest vertical slice and L0
M4  Versioned schema, durable time-series storage, and query API
M5  Device state, WebSocket delivery, and message trace
M6  Rules, alerts, and retryable event/webhook delivery
M7  Command/RPC lifecycle with ACK and idempotency
M8  Queue/backpressure and multi-worker failure behavior
M9  EAMO/MES consumer contract and integration example
M10 Progressive benchmarks, recovery, and release hardening
~~~

Each milestone has a happy-path demo, a failure/load exercise, tenant-isolation tests, recorded metrics, and a decision note naming the next bottleneck. Ordering may be refined by evidence, but feature breadth must not bypass the L0 secure-ingestion gate.

### Version roadmap

~~~text
v0.1  Current tenant/project/asset foundation and reproducible local environment
v0.2  Device identity, broker authentication, secure MQTT ingest, and L0
v0.3  Schema validation, time-series persistence, and query API
v0.4  Device state, WebSocket, and message processing trace
v0.5  Rules, alerts, outbound events, and command/ACK
v0.6  Queue/backpressure, multi-worker behavior, and failure testing
v0.7  EAMO/MES integration examples and broker compatibility
v0.8  Measured performance, retention, and operational documentation
v1.0  Stable API/protocol contracts, deployment guide, security and recovery gates
~~~

### Definition of successful V1

LiteBoard is a self-hosted multi-tenant IoT platform with Device management, authenticated MQTT ingestion, schema validation, queryable time-series telemetry, device state, rules/alerts, command/ACK, REST/WebSocket contracts, and operational observability. Its limits and failure behavior are documented from measured experiments; it does not claim to replace all ThingsBoard features.

## 14. Repository and Documentation

Current repository map:

~~~text
liteboard/
├── backend/                 # Laravel Control Plane
├── telemetry-service/       # Python MQTT Data Plane
├── web/                     # Vue / Nuxt application
├── simulator/               # Python device simulator
├── infrastructure/          # Broker and monitoring configuration
├── docs/
└── docker-compose.yml
~~~

The current Compose frontend service uses the `web/` build context, matching this checkout. Verify a clean, reproducible startup and the configured health checks before documenting a successful one-command local workflow; the configuration match alone does not prove the Phase 0 gate.

Recommended supporting documentation:

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

README quick start should show:

~~~text
ESP32 → EMQX / Mosquitto → LiteBoard → Dashboard / EAMO / MES
~~~

Include API/MQTT contracts, sample device client, Python simulator, broker setup, downstream integration example, contribution guidance, and measured benchmark reports.

## 15. Technical Outcomes and Long-Term Direction

LiteBoard is a system engineering lab for backend architecture, PostgreSQL/time-series storage, Redis and messaging, event-driven processing, distributed-systems reliability, Linux, Docker, networking, observability, and performance testing.

The learning sequence is deliberate: prove a small correct vertical slice, measure it, inject failures, understand the bottleneck, then change one part and measure again. Idempotency, message ordering, retry, backpressure, reconnect, tenant isolation, database optimization, and horizontal scaling are recurring engineering concerns, not one-time checklist items.

Deferred until core behavior and measurements justify them: additional device protocols, generalized dashboard composition, arbitrary workflows, OTA/device shadow, advanced analytics or ML, broker clustering, multi-region deployments, and very high device-count targets.

> **LiteBoard is an open-source multi-tenant IoT platform focused on real-time telemetry, device management, and distributed backend architecture.**

## 16. Production Readiness Checklist

The development plan is not production-ready only because the platform starts with Docker Compose. Production release requires the following controls to be implemented, tested and documented.

### Security

- MQTT clients use TLS on port 8883 only; the broker must not expose plaintext MQTT on 1883. HTTP/WebSocket traffic also requires TLS in production.
- Broker ACL scoped to the project and Device; Device credentials can be revoked and rotated. Anonymous access is development-only.
- Secrets supplied through environment or secret management and never committed to Git.
- API rate limiting, MQTT connection/message limits and maximum payload size.
- Webhook SSRF protection, URL validation and outbound network restrictions.
- Audit logs for authentication, permission changes, Device credentials, commands, rules and alerts.
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
- Forward-only schema migration and compensating-change procedure, plus an application rollback plan compatible with the current schema.

### SLO and observability

Set SLOs per deployment environment after collecting a baseline. The values below are examples to evaluate, not achieved results or committed service guarantees:

~~~text
Telemetry ingestion success rate  > 99.9%
p95 processing latency            < 500 ms
API availability                  > 99.9%
~~~

Add correlation IDs from MQTT message through storage, rule evaluation, webhook and WebSocket delivery. Configure alerts for queue growth, database latency, broker disconnects, error rate, disk usage, certificate expiry and worker health. Keep log retention rules and an incident runbook for each critical alert.

### Data management

- Time-based partitioning or TimescaleDB hypertables for telemetry where justified.
- Automated retention, aggregation and downsampling jobs.
- Schema compatibility/version policy for devices and firmware.
- Data quality checks for duplicates, late data and missing intervals.
- Tenant isolation and authorization tests on every tenant-owned access path.
- Encryption at rest and controlled access for sensitive application data.

### Delivery and operations

- CI pipeline covering unit, integration, API, migration, lint, type, security and image checks.
- Staging environment that matches production topology.
- Immutable versioned images and versioned configuration.
- Automated forward-only migration, smoke test and compatible application rollback steps.
- Blue-green or canary deployment when downtime is unacceptable.
- Resource limits, capacity plan, log rotation and host-level monitoring.
- Patch process for the OS, runtime, dependencies, broker and database.
- Documented ownership, escalation path, incident response and maintenance windows.

### Production load validation

Before go-live, run the Benchmark Plan at the highest intended deployment level and also run:

Capacity experiments beyond that level remain optional and are not generic guarantees.

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
