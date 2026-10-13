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
"""

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
FOR SHARE OF d, p, t
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
RETURNING d.id
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
    id, project_id, device_id, message_id, status, reason_codes, received_at
)
VALUES ($1::uuid, $2::uuid, $3::uuid, $4, $5, $6::jsonb, $7)
"""
