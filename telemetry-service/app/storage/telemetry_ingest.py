from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import datetime
from typing import Literal
from uuid import uuid4

from asyncpg import Connection
from asyncpg.pool import Pool

from app.telemetry.envelope import EnvelopeInspection, inspect_telemetry_envelope
from app.telemetry.validation import Rejection, validate_telemetry

IngestStatus = Literal['persisted', 'rejected', 'duplicate', 'idempotency_conflict', 'unresolved']

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


@dataclass(frozen=True)
class IngestResult:
    status: IngestStatus
    reasons: tuple[Rejection, ...] = ()


class PostgresTelemetryIngestor:
    def __init__(self, db_pool: Pool) -> None:
        self._db_pool = db_pool

    async def ingest(
        self,
        *,
        project_id: str,
        device_id: str,
        raw: bytes,
        retained: bool,
    ) -> IngestResult:
        async with self._db_pool.acquire() as connection:
            async with connection.transaction():
                device = await connection.fetchrow(SELECT_DEVICE_CONTEXT_SQL, device_id, project_id)
                if device is None:
                    return IngestResult('unresolved')

                marked_device_id = await connection.fetchval(MARK_DEVICE_PRESENCE_SQL, device_id, project_id)
                if marked_device_id is None:
                    return IngestResult('unresolved')

                inspection = inspect_telemetry_envelope(raw, received_at=device['received_at'])
                if retained:
                    reasons = (Rejection('retained_message'),)
                    await self._record_outcome(
                        connection,
                        project_id=project_id,
                        device_id=device_id,
                        message_id=inspection.message_id,
                        status='rejected',
                        reasons=reasons,
                        received_at=device['received_at'],
                    )
                    return IngestResult('rejected', reasons)

                duplicate_result = await self._find_duplicate(
                    connection,
                    project_id=project_id,
                    device_id=device_id,
                    inspection=inspection,
                    received_at=device['received_at'],
                )
                if duplicate_result is not None:
                    return duplicate_result

                reasons = list(inspection.reasons)
                if not reasons and device['device_profile_version_id'] is None:
                    reasons.append(Rejection('schema_not_assigned'))
                elif not reasons:
                    definition = device['definition']
                    if isinstance(definition, str):
                        definition = json.loads(definition)

                    validation = validate_telemetry(
                        definition=definition,
                        version=device['version'],
                        payload=inspection.payload,
                    )
                    reasons.extend(validation.reasons)

                if reasons:
                    result_reasons = tuple(reasons)
                    await self._record_outcome(
                        connection,
                        project_id=project_id,
                        device_id=device_id,
                        message_id=inspection.message_id,
                        status='rejected',
                        reasons=result_reasons,
                        received_at=device['received_at'],
                    )
                    return IngestResult('rejected', result_reasons)

                envelope = inspection.envelope
                sample_id = str(uuid4())
                await connection.execute(
                    INSERT_SAMPLE_SQL,
                    sample_id,
                    project_id,
                    device_id,
                    str(device['device_profile_version_id']),
                    envelope.message_id,
                    envelope.occurred_at,
                    device['received_at'],
                    json.dumps(
                        envelope.values,
                        sort_keys=True,
                        separators=(',', ':'),
                        ensure_ascii=False,
                        allow_nan=False,
                    ),
                )
                await connection.execute(
                    INSERT_MESSAGE_IDENTITY_SQL,
                    str(uuid4()),
                    project_id,
                    device_id,
                    envelope.message_id,
                    inspection.content_hash,
                    device['received_at'],
                )
                await self._record_outcome(
                    connection,
                    project_id=project_id,
                    device_id=device_id,
                    message_id=envelope.message_id,
                    status='persisted',
                    reasons=(),
                    received_at=device['received_at'],
                )
                return IngestResult('persisted')

    async def _find_duplicate(
        self,
        connection: Connection,
        *,
        project_id: str,
        device_id: str,
        inspection: EnvelopeInspection,
        received_at: datetime,
    ) -> IngestResult | None:
        if inspection.message_id is None or inspection.content_hash is None:
            return None

        await connection.fetchval(LOCK_MESSAGE_IDENTITY_SQL, device_id, inspection.message_id)
        existing = await connection.fetchrow(
            SELECT_MESSAGE_IDENTITY_SQL,
            project_id,
            device_id,
            inspection.message_id,
        )
        if existing is None:
            return None

        status: Literal['duplicate', 'idempotency_conflict'] = (
            'duplicate' if existing['content_hash'] == inspection.content_hash else 'idempotency_conflict'
        )
        reasons = () if status == 'duplicate' else (Rejection('idempotency_conflict', 'message_id'),)
        await self._record_outcome(
            connection,
            project_id=project_id,
            device_id=device_id,
            message_id=inspection.message_id,
            status=status,
            reasons=reasons,
            received_at=received_at,
        )
        return IngestResult(status, reasons)

    async def _record_outcome(
        self,
        connection: Connection,
        *,
        project_id: str,
        device_id: str,
        message_id: str | None,
        status: Literal['persisted', 'rejected', 'duplicate', 'idempotency_conflict'],
        reasons: tuple[Rejection, ...],
        received_at: datetime,
    ) -> None:
        await connection.execute(
            INSERT_INGEST_OUTCOME_SQL,
            str(uuid4()),
            project_id,
            device_id,
            message_id,
            status,
            json.dumps([{'code': reason.code, 'field': reason.field} for reason in reasons]),
            received_at,
        )
