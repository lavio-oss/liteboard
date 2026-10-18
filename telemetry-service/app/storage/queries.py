UPDATE_DEVICE_PRESENCE_SQL = """
UPDATE devices d
SET connection_state = 'online',
    last_seen_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
FROM projects p
JOIN tenants t ON t.id = p.tenant_id
WHERE d.id = $1::uuid
  AND d.project_id = $2::uuid
  AND p.id = d.project_id
  AND d.status = 'active'
  AND d.approval_status = 'approved'
  AND p.status = 'active'
  AND t.status = 'active'
RETURNING t.id AS tenant_id, p.id AS project_id, d.id AS device_id,
          d.connection_state, d.last_seen_at, d.updated_at
"""

# Locks the device row at the strength MARK_DEVICE_PRESENCE_SQL needs. Taking FOR SHARE first and
# upgrading in the UPDATE deadlocks two concurrent messages from the same device.
SELECT_DEVICE_CONTEXT_SQL = """
SELECT d.device_profile_version_id, dpv.version, dpv.definition, d.equipment_asset_id,
       CURRENT_TIMESTAMP AS received_at
FROM devices d
JOIN projects p ON p.id = d.project_id
JOIN tenants t ON t.id = p.tenant_id
LEFT JOIN device_profile_versions dpv
  ON dpv.id = d.device_profile_version_id
 AND dpv.project_id = d.project_id
WHERE d.id = $1::uuid
  AND d.project_id = $2::uuid
  AND d.status = 'active'
  AND d.approval_status = 'approved'
  AND p.status = 'active'
  AND t.status = 'active'
FOR NO KEY UPDATE OF d FOR SHARE OF p, t
"""

MARK_DEVICE_PRESENCE_SQL = """
UPDATE devices d
SET connection_state = 'online',
    last_seen_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
FROM projects p
JOIN tenants t ON t.id = p.tenant_id
WHERE d.id = $1::uuid
  AND d.project_id = $2::uuid
  AND p.id = d.project_id
  AND d.status = 'active'
  AND d.approval_status = 'approved'
  AND p.status = 'active'
  AND t.status = 'active'
RETURNING t.id AS tenant_id, p.id AS project_id, d.id AS device_id,
          d.connection_state, d.last_seen_at, d.updated_at
"""

UPDATE_STALE_DEVICE_PRESENCE_SQL = """
UPDATE devices d
SET connection_state = CASE
        WHEN d.last_seen_at IS NULL OR d.last_seen_at < CURRENT_TIMESTAMP - INTERVAL '90 seconds' THEN 'offline'
        WHEN d.last_seen_at < CURRENT_TIMESTAMP - INTERVAL '30 seconds' THEN 'unstable'
        ELSE 'online'
    END,
    updated_at = CURRENT_TIMESTAMP
FROM projects p
JOIN tenants t ON t.id = p.tenant_id
WHERE p.id = d.project_id
  AND d.connection_state IS DISTINCT FROM CASE
        WHEN d.last_seen_at IS NULL OR d.last_seen_at < CURRENT_TIMESTAMP - INTERVAL '90 seconds' THEN 'offline'
        WHEN d.last_seen_at < CURRENT_TIMESTAMP - INTERVAL '30 seconds' THEN 'unstable'
        ELSE 'online'
    END
RETURNING t.id AS tenant_id, p.id AS project_id, d.id AS device_id,
          d.connection_state, d.last_seen_at, d.updated_at
"""

LOCK_MESSAGE_IDENTITY_SQL = """
SELECT pg_advisory_xact_lock(hashtextextended($1 || ':' || $2, 0))
"""

SELECT_MESSAGE_IDENTITY_SQL = """
SELECT content_hash
FROM telemetry_message_identities
WHERE project_id = $1::uuid
  AND device_id = $2::uuid
  AND message_id = $3
FOR UPDATE
"""

INSERT_SAMPLE_SQL = """
INSERT INTO telemetry_samples (
    id, project_id, device_id, device_profile_version_id, message_id,
    occurred_at, received_at, "values", status
)
VALUES ($1::uuid, $2::uuid, $3::uuid, $4::uuid, $5, $6, $7, $8::jsonb, 'persisted')
"""

INSERT_MESSAGE_IDENTITY_SQL = """
INSERT INTO telemetry_message_identities (
    id, project_id, device_id, message_id, content_hash, created_at
)
VALUES ($1::uuid, $2::uuid, $3::uuid, $4, $5, $6)
"""

INSERT_INGEST_OUTCOME_SQL = """
INSERT INTO telemetry_ingest_outcomes (
    id, project_id, device_id, message_id, status, reason_codes, received_at, processing_id, stages
)
VALUES ($1::uuid, $2::uuid, $3::uuid, $4, $5, $6::jsonb, $7, $8::uuid, $9::jsonb)
"""

APPEND_OUTCOME_STAGE_SQL = """
UPDATE telemetry_ingest_outcomes
SET stages = stages || $2::jsonb
WHERE processing_id = $1::uuid
"""

SELECT_DEVICE_SCOPE_STATUS_SQL = """
SELECT d.project_id, d.status, d.approval_status, p.status AS project_status, t.status AS tenant_status,
       CURRENT_TIMESTAMP AS received_at
FROM devices d
JOIN projects p ON p.id = d.project_id
JOIN tenants t ON t.id = p.tenant_id
WHERE d.id = $1::uuid
"""

UPSERT_LATEST_VALUES_SQL = """
INSERT INTO telemetry_latest_values (
    project_id, device_id, metric, value, sample_id, message_id, occurred_at, received_at
)
SELECT $1::uuid, $2::uuid, kv.key, kv.value, $3::uuid, $4, $5, $6
FROM jsonb_each($7::jsonb) AS kv(key, value)
ON CONFLICT (device_id, metric) DO UPDATE
SET value = EXCLUDED.value,
    sample_id = EXCLUDED.sample_id,
    message_id = EXCLUDED.message_id,
    occurred_at = EXCLUDED.occurred_at,
    received_at = EXCLUDED.received_at
WHERE (telemetry_latest_values.occurred_at, telemetry_latest_values.received_at, telemetry_latest_values.sample_id)
    < (EXCLUDED.occurred_at, EXCLUDED.received_at, EXCLUDED.sample_id)
"""

INSERT_UNRESOLVED_MESSAGE_SQL = """
INSERT INTO telemetry_unresolved_messages (processing_id, topic, reason, payload_size, stages, received_at)
VALUES ($1::uuid, $2, $3, $4, $5::jsonb, CURRENT_TIMESTAMP)
"""

# Queued only when the profile has an active rule; equipment is snapshotted at sample time.
INSERT_EVALUATION_JOB_SQL = """
INSERT INTO telemetry_evaluation_jobs (
    sample_id, project_id, device_id, device_profile_version_id, equipment_asset_id, received_at
)
SELECT $1::uuid, $2::uuid, $3::uuid, $4::uuid, $5::uuid, $6
WHERE EXISTS (
    SELECT 1
    FROM alert_rules r
    JOIN device_profile_versions v
      ON v.device_profile_id = r.device_profile_id
     AND v.project_id = r.project_id
    WHERE v.id = $4::uuid
      AND r.project_id = $2::uuid
      AND r.status = 'active'
)
"""

SELECT_PENDING_EVALUATION_DEVICES_SQL = """
SELECT device_id
FROM telemetry_evaluation_jobs
WHERE evaluated_at IS NULL
GROUP BY device_id
ORDER BY MIN(id)
LIMIT $1
"""

TRY_LOCK_EVALUATION_DEVICE_SQL = """
SELECT pg_try_advisory_xact_lock(hashtextextended('rule-evaluation:' || $1::text, 0))
"""

SELECT_PENDING_EVALUATION_JOBS_SQL = """
SELECT j.id, j.sample_id, j.project_id, j.device_id, j.device_profile_version_id, j.equipment_asset_id,
       j.received_at, s.message_id, s.occurred_at, s."values"
FROM telemetry_evaluation_jobs j
JOIN telemetry_samples s ON s.id = j.sample_id
WHERE j.device_id = $1::uuid
  AND j.evaluated_at IS NULL
ORDER BY j.received_at, j.sample_id
LIMIT $2
"""

SELECT_ACTIVE_RULES_FOR_VERSIONS_SQL = """
SELECT v.id AS device_profile_version_id, r.id AS rule_id, r.code AS rule_code,
       rv.id AS revision_id, rv.revision, rv.definition
FROM alert_rules r
JOIN alert_rule_revisions rv ON rv.id = r.current_revision_id
JOIN device_profile_versions v
  ON v.device_profile_id = r.device_profile_id
 AND v.project_id = r.project_id
WHERE v.id = ANY($1::uuid[])
  AND r.project_id = $2::uuid
  AND r.status = 'active'
ORDER BY r.id
FOR SHARE OF r
"""

SELECT_EVALUATION_STATES_SQL = """
SELECT alert_rule_id, alert_rule_revision_id, trigger_since, clear_since, cursor_received_at, cursor_sample_id
FROM rule_evaluation_states
WHERE device_id = $1::uuid
ORDER BY alert_rule_id
FOR UPDATE
"""

SELECT_ACTIVE_DEVICE_ALERTS_SQL = """
SELECT id, alert_rule_id, severity
FROM alerts
WHERE device_id = $1::uuid
  AND cleared_at IS NULL
ORDER BY id
FOR UPDATE
"""

UPSERT_EVALUATION_STATE_SQL = """
INSERT INTO rule_evaluation_states (
    device_id, alert_rule_id, project_id, alert_rule_revision_id,
    trigger_since, clear_since, cursor_received_at, cursor_sample_id, updated_at
)
VALUES ($1::uuid, $2::uuid, $3::uuid, $4::uuid, $5, $6, $7, $8::uuid, CURRENT_TIMESTAMP)
ON CONFLICT (device_id, alert_rule_id) DO UPDATE
SET alert_rule_revision_id = EXCLUDED.alert_rule_revision_id,
    trigger_since = EXCLUDED.trigger_since,
    clear_since = EXCLUDED.clear_since,
    cursor_received_at = EXCLUDED.cursor_received_at,
    cursor_sample_id = EXCLUDED.cursor_sample_id,
    updated_at = EXCLUDED.updated_at
"""

MARK_EVALUATION_JOBS_DONE_SQL = """
UPDATE telemetry_evaluation_jobs
SET evaluated_at = clock_timestamp()
WHERE id = ANY($1::bigint[])
"""

INSERT_ALERT_SQL = """
INSERT INTO alerts (
    id, project_id, device_id, equipment_asset_id, alert_rule_id, alert_rule_revision_id,
    device_profile_version_id, severity, opened_at, last_seen_at, open_sample_id, open_message_id,
    evidence, created_at, updated_at
)
VALUES ($1::uuid, $2::uuid, $3::uuid, $4::uuid, $5::uuid, $6::uuid, $7::uuid, $8, $9, $9, $10::uuid, $11,
        $12::jsonb, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
"""

TOUCH_ALERT_SQL = """
UPDATE alerts
SET last_seen_at = GREATEST(last_seen_at, $2), updated_at = CURRENT_TIMESTAMP
WHERE id = $1::uuid
"""

CHANGE_ALERT_SEVERITY_SQL = """
UPDATE alerts
SET severity = $2, alert_rule_revision_id = $3::uuid, updated_at = CURRENT_TIMESTAMP
WHERE id = $1::uuid
"""

CLEAR_ALERT_SQL = """
UPDATE alerts
SET cleared_at = $2, clear_reason = 'condition_cleared', clear_sample_id = $3::uuid, clear_message_id = $4,
    updated_at = CURRENT_TIMESTAMP
WHERE id = $1::uuid
  AND cleared_at IS NULL
"""

RESET_OFFLINE_EVALUATION_STATES_SQL = """
UPDATE rule_evaluation_states s
SET trigger_since = NULL,
    clear_since = NULL,
    cursor_received_at = GREATEST(COALESCE(s.cursor_received_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP),
    cursor_sample_id = NULL,
    updated_at = CURRENT_TIMESTAMP
FROM (
    SELECT device_id, alert_rule_id
    FROM rule_evaluation_states
    WHERE device_id = ANY($1::uuid[])
    ORDER BY device_id, alert_rule_id
    FOR UPDATE
) locked
WHERE s.device_id = locked.device_id
  AND s.alert_rule_id = locked.alert_rule_id
"""

CLEAR_OFFLINE_ALERTS_SQL = """
UPDATE alerts a
SET cleared_at = CURRENT_TIMESTAMP, clear_reason = 'device_offline', updated_at = CURRENT_TIMESTAMP
FROM (
    SELECT id
    FROM alerts
    WHERE device_id = ANY($1::uuid[])
      AND cleared_at IS NULL
    ORDER BY id
    FOR UPDATE
) locked
WHERE a.id = locked.id
RETURNING a.id, a.project_id, a.alert_rule_revision_id, a.cleared_at
"""

INSERT_ALERT_EVENT_SQL = """
INSERT INTO alert_events (
    id, project_id, alert_id, type, occurred_at, actor_id, alert_rule_revision_id, sample_id, message_id, data
)
VALUES ($1::uuid, $2::uuid, $3::uuid, $4, $5, NULL, $6::uuid, $7::uuid, $8, $9::jsonb)
"""

SELECT_ALERT_SNAPSHOT_SQL = """
SELECT a.id, a.project_id, p.tenant_id, a.alert_rule_id, r.code AS rule_code, rv.revision AS rule_revision,
       a.severity, a.device_id, a.equipment_asset_id, a.opened_at, a.cleared_at, a.clear_reason,
       a.acknowledged_at
FROM alerts a
JOIN projects p ON p.id = a.project_id
JOIN alert_rules r ON r.id = a.alert_rule_id
JOIN alert_rule_revisions rv ON rv.id = a.alert_rule_revision_id
WHERE a.id = $1::uuid
"""

INSERT_OUTBOX_EVENT_SQL = """
INSERT INTO outbox_events (id, project_id, event_type, aggregate_id, idempotency_key, payload, created_at)
VALUES ($1::uuid, $2::uuid, $3, $4::uuid, $5, $6::jsonb, CURRENT_TIMESTAMP)
ON CONFLICT (idempotency_key) DO NOTHING
"""
