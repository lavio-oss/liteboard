from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any, Literal
from uuid import uuid4

from asyncpg import Connection
from asyncpg.pool import Pool

from app.storage.queries import (
    APPEND_OUTCOME_STAGE_SQL,
    INSERT_EVALUATION_JOB_SQL,
    INSERT_INGEST_OUTCOME_SQL,
    INSERT_MESSAGE_IDENTITY_SQL,
    INSERT_SAMPLE_SQL,
    INSERT_UNRESOLVED_MESSAGE_SQL,
    LOCK_MESSAGE_IDENTITY_SQL,
    MARK_DEVICE_PRESENCE_SQL,
    SELECT_DEVICE_CONTEXT_SQL,
    SELECT_DEVICE_SCOPE_STATUS_SQL,
    SELECT_MESSAGE_IDENTITY_SQL,
    UPSERT_LATEST_VALUES_SQL,
)
from app.storage.presence import DevicePresence, device_presence_from_row
from app.telemetry.decisions import (
    DeviceScope,
    ProfileAssignment,
    Reject,
    classify_duplicate,
    classify_unresolved,
    decide_admission,
)
from app.telemetry.envelope import inspect_telemetry_envelope, inspect_unprofiled_telemetry
from app.telemetry.validation import Rejection

IngestStatus = Literal['persisted', 'rejected', 'duplicate', 'idempotency_conflict', 'unresolved', 'skipped']


@dataclass(frozen=True)
class PersistedSample:
    id: str
    project_id: str
    device_id: str
    device_profile_version_id: str | None
    message_id: str
    occurred_at: datetime
    received_at: datetime
    values: dict[str, Any]


@dataclass(frozen=True)
class RecordedOutcome:
    id: str
    project_id: str
    device_id: str
    processing_id: str
    message_id: str | None
    status: IngestStatus
    reason_codes: tuple[Rejection, ...]
    stages: tuple[dict[str, str], ...]
    received_at: datetime


@dataclass(frozen=True)
class IngestResult:
    status: IngestStatus
    processing_id: str
    reasons: tuple[Rejection, ...] = ()
    sample: PersistedSample | None = None
    outcome: RecordedOutcome | None = None
    presence: DevicePresence | None = None


def _stage(name: str, at: datetime | None = None) -> dict[str, str]:
    return {'stage': name, 'at': (at or datetime.now(timezone.utc)).isoformat()}


class PostgresTelemetryIngestor:
    """Executes ingest decisions inside one PostgreSQL transaction; the rules live in app.telemetry.decisions."""

    def __init__(self, db_pool: Pool) -> None:
        self._db_pool = db_pool

    async def ingest(
        self,
        *,
        project_id: str,
        device_id: str,
        raw: bytes,
        retained: bool,
        received_wall: datetime,
    ) -> IngestResult:
        processing_id = str(uuid4())
        stages = [_stage('received', received_wall)]
        async with self._db_pool.acquire() as connection:
            async with connection.transaction():
                device = await connection.fetchrow(SELECT_DEVICE_CONTEXT_SQL, device_id, project_id)
                if device is None:
                    return await self._record_unresolved(
                        connection,
                        project_id=project_id,
                        device_id=device_id,
                        raw=raw,
                        processing_id=processing_id,
                        stages=stages,
                    )

                # SELECT_DEVICE_CONTEXT_SQL holds the device/project/tenant row locks, so scope cannot change here.
                presence_row = await connection.fetchrow(MARK_DEVICE_PRESENCE_SQL, device_id, project_id)
                if presence_row is None:
                    raise RuntimeError('The resolved device could not be marked online.')
                presence = device_presence_from_row(presence_row)
                stages.append(_stage('resolved'))
                received_at = device['received_at']

                async def record(
                    status: IngestStatus,
                    reasons: tuple[Rejection, ...],
                    message_id: str | None,
                ) -> RecordedOutcome:
                    return await self._record_outcome(
                        connection,
                        project_id=project_id,
                        device_id=device_id,
                        message_id=message_id,
                        status=status,
                        reasons=reasons,
                        received_at=received_at,
                        processing_id=processing_id,
                        stages=stages,
                    )

                profile = self._profile_assignment(device)
                inspection = (
                    inspect_telemetry_envelope(raw, received_at=received_at)
                    if profile is not None
                    else inspect_unprofiled_telemetry(raw, received_at=received_at)
                )
                stages.append(_stage('decoded'))
                # Retained telemetry is rejected before dedup so a retained replay is never counted as a duplicate.
                if retained:
                    reasons = (Rejection('retained_message'),)
                    outcome = await record('rejected', reasons, inspection.message_id)
                    return IngestResult('rejected', processing_id, reasons, outcome=outcome, presence=presence)

                if inspection.message_id is not None and inspection.content_hash is not None:
                    # The advisory lock serializes the same (device, message_id) across concurrent workers.
                    await connection.fetchval(LOCK_MESSAGE_IDENTITY_SQL, device_id, inspection.message_id)
                    existing = await connection.fetchrow(
                        SELECT_MESSAGE_IDENTITY_SQL,
                        project_id,
                        device_id,
                        inspection.message_id,
                    )
                    duplicate = classify_duplicate(
                        inspection.content_hash,
                        None if existing is None else existing['content_hash'],
                    )
                    if duplicate is not None:
                        outcome = await record(duplicate.status, duplicate.reasons, inspection.message_id)
                        return IngestResult(duplicate.status, processing_id, duplicate.reasons, outcome=outcome, presence=presence)
                stages.append(_stage('dedup_checked'))

                if profile is None:
                    if inspection.reasons:
                        outcome = await record('rejected', inspection.reasons, inspection.message_id)
                        return IngestResult('rejected', processing_id, inspection.reasons, outcome=outcome, presence=presence)
                    stages.append(_stage('schema_validation_skipped'))
                    if inspection.envelope is None:
                        raise AssertionError('A reason-free profile-less inspection must carry an envelope.')
                    envelope = inspection.envelope
                else:
                    admission = decide_admission(inspection, profile)
                    stages.append(_stage('validated'))
                    if isinstance(admission, Reject):
                        outcome = await record('rejected', admission.reasons, inspection.message_id)
                        return IngestResult('rejected', processing_id, admission.reasons, outcome=outcome, presence=presence)
                    envelope = admission.envelope

                sample = PersistedSample(
                    id=str(uuid4()),
                    project_id=project_id,
                    device_id=device_id,
                    device_profile_version_id=None if profile is None else str(device['device_profile_version_id']),
                    message_id=envelope.message_id,
                    occurred_at=envelope.occurred_at,
                    received_at=received_at,
                    values=envelope.values,
                )
                await self._write_sample(connection, sample, inspection.content_hash, device['equipment_asset_id'])
                outcome = await record('persisted', (), sample.message_id)
                return IngestResult('persisted', processing_id, sample=sample, outcome=outcome, presence=presence)

    async def record_invalid_topic(self, *, topic: str, payload_size: int, received_wall: datetime) -> str:
        processing_id = str(uuid4())
        stages = [_stage('received', received_wall), _stage('commit')]
        await self._db_pool.execute(
            INSERT_UNRESOLVED_MESSAGE_SQL,
            processing_id,
            topic[:255],
            'invalid_topic',
            payload_size,
            json.dumps(stages),
        )
        return processing_id

    async def record_broadcast(self, processing_id: str, subscribers: int) -> None:
        stage = {**_stage('broadcast'), 'subscribers': subscribers}
        await self._db_pool.execute(APPEND_OUTCOME_STAGE_SQL, processing_id, json.dumps([stage]))

    @staticmethod
    def _profile_assignment(device: Any) -> ProfileAssignment | None:
        if device['device_profile_version_id'] is None:
            return None
        definition = device['definition']
        # asyncpg returns jsonb as text unless a codec is registered.
        return ProfileAssignment(
            version=device['version'],
            definition=json.loads(definition) if isinstance(definition, str) else definition,
        )

    async def _write_sample(
        self,
        connection: Connection,
        sample: PersistedSample,
        content_hash: str | None,
        equipment_asset_id: Any,
    ) -> None:
        values_json = json.dumps(
            sample.values,
            sort_keys=True,
            separators=(',', ':'),
            ensure_ascii=False,
            allow_nan=False,
        )
        await connection.execute(
            INSERT_SAMPLE_SQL,
            sample.id,
            sample.project_id,
            sample.device_id,
            sample.device_profile_version_id,
            sample.message_id,
            sample.occurred_at,
            sample.received_at,
            values_json,
        )
        await connection.execute(
            UPSERT_LATEST_VALUES_SQL,
            sample.project_id,
            sample.device_id,
            sample.id,
            sample.message_id,
            sample.occurred_at,
            sample.received_at,
            values_json,
        )
        await connection.execute(
            INSERT_MESSAGE_IDENTITY_SQL,
            str(uuid4()),
            sample.project_id,
            sample.device_id,
            sample.message_id,
            content_hash,
            sample.received_at,
        )
        if sample.device_profile_version_id is not None:
            await connection.execute(
                INSERT_EVALUATION_JOB_SQL,
                sample.id,
                sample.project_id,
                sample.device_id,
                sample.device_profile_version_id,
                None if equipment_asset_id is None else str(equipment_asset_id),
                sample.received_at,
            )

    async def _record_unresolved(
        self,
        connection: Connection,
        *,
        project_id: str,
        device_id: str,
        raw: bytes,
        processing_id: str,
        stages: list[dict[str, str]],
    ) -> IngestResult:
        row = await connection.fetchrow(SELECT_DEVICE_SCOPE_STATUS_SQL, device_id)
        scope = None if row is None else DeviceScope(
            project_id=str(row['project_id']),
            status=row['status'],
            approval_status=row['approval_status'],
            project_status=row['project_status'],
            tenant_status=row['tenant_status'],
        )
        verdict = classify_unresolved(scope, project_id)

        if not isinstance(verdict, Rejection):
            stages.append(_stage('commit'))
            await connection.execute(
                INSERT_UNRESOLVED_MESSAGE_SQL,
                processing_id,
                f'liteboard/{project_id}/devices/{device_id}/telemetry',
                verdict,
                len(raw),
                json.dumps(stages),
            )
            return IngestResult('unresolved', processing_id)

        inspection = inspect_telemetry_envelope(raw, received_at=row['received_at'])
        outcome = await self._record_outcome(
            connection,
            project_id=project_id,
            device_id=device_id,
            message_id=inspection.message_id,
            status='unresolved',
            reasons=(verdict,),
            received_at=row['received_at'],
            processing_id=processing_id,
            stages=stages,
        )
        return IngestResult('unresolved', processing_id, (verdict,), outcome=outcome)

    async def _record_outcome(
        self,
        connection: Connection,
        *,
        project_id: str,
        device_id: str,
        message_id: str | None,
        status: IngestStatus,
        reasons: tuple[Rejection, ...],
        received_at: datetime,
        processing_id: str,
        stages: list[dict[str, str]],
    ) -> RecordedOutcome:
        # Recorded just before COMMIT; the transaction commits right after this insert.
        stages.append(_stage('commit'))
        outcome_id = str(uuid4())
        await connection.execute(
            INSERT_INGEST_OUTCOME_SQL,
            outcome_id,
            project_id,
            device_id,
            message_id,
            status,
            json.dumps([{'code': reason.code, 'field': reason.field} for reason in reasons]),
            received_at,
            processing_id,
            json.dumps(stages),
        )
        return RecordedOutcome(
            id=outcome_id,
            project_id=project_id,
            device_id=device_id,
            processing_id=processing_id,
            message_id=message_id,
            status=status,
            reason_codes=reasons,
            stages=tuple(dict(stage) for stage in stages),
            received_at=received_at,
        )
