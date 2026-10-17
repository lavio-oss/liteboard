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
SELECT d.device_profile_version_id, dpv.version, dpv.definition, CURRENT_TIMESTAMP AS received_at
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
