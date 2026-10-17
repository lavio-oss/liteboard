from __future__ import annotations

import json
import unittest
from datetime import datetime, timezone
from uuid import UUID

from app.storage.queries import (
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
from app.storage.telemetry_ingest import PostgresTelemetryIngestor
from app.telemetry.envelope import inspect_telemetry_envelope, inspect_unprofiled_telemetry

PROJECT_ID = '123e4567-e89b-12d3-a456-426614174000'
DEVICE_ID = '123e4567-e89b-12d3-a456-426614174001'
TENANT_ID = '123e4567-e89b-12d3-a456-426614174010'
OTHER_PROJECT_ID = '123e4567-e89b-12d3-a456-426614174009'
PROFILE_VERSION_ID = '123e4567-e89b-12d3-a456-426614174002'
RECEIVED_AT = datetime(2026, 10, 11, 4, 0, tzinfo=timezone.utc)
DEFINITION = {
    'strict_mode': True,
    'fields': {'temperature': {'type': 'float', 'unit': 'celsius', 'required': True, 'max': 150}},
}
BLOCKED_ROW = {
    'project_id': UUID('123e4567-e89b-12d3-a456-426614174000'),
    'status': 'active',
    'approval_status': 'pending',
    'project_status': 'active',
    'tenant_status': 'active',
    'received_at': datetime(2026, 10, 11, 4, 0, tzinfo=timezone.utc),
}
RAW = b'{"message_id":"msg-1","schema_version":1,"occurred_at":"2026-10-11T03:59:00Z","values":{"temperature":40}}'


class PostgresTelemetryIngestorTest(unittest.IsolatedAsyncioTestCase):
    async def test_commits_presence_sample_identity_and_persisted_outcome_atomically(self) -> None:
        connection = _FakeConnection()

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=RAW,
            retained=False,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('persisted', result.status)
        self.assertEqual(TENANT_ID, result.presence.tenant_id)
        self.assertIsNotNone(result.sample)
        self.assertEqual('msg-1', result.sample.message_id)
        self.assertEqual({'temperature': 40}, result.sample.values)
        self.assertIsNotNone(result.outcome)
        self.assertEqual('persisted', result.outcome.status)
        self.assertEqual('msg-1', result.outcome.message_id)
        self.assertEqual(['received', 'resolved', 'decoded', 'dedup_checked', 'validated', 'commit'], [
            stage['stage'] for stage in result.outcome.stages
        ])
        self.assertEqual('commit', connection.events[-1])
        self.assertEqual(1, connection.events.count('transaction.begin'))
        self.assertEqual(MARK_DEVICE_PRESENCE_SQL, connection.presence_sql)
        self.assertEqual(
            [INSERT_SAMPLE_SQL, UPSERT_LATEST_VALUES_SQL, INSERT_MESSAGE_IDENTITY_SQL, INSERT_INGEST_OUTCOME_SQL],
            connection.executed_sql,
        )
        sample = connection.executed[0]
        latest = connection.executed[1]
        self.assertEqual((PROJECT_ID, DEVICE_ID, sample[0], 'msg-1', sample[5], sample[6], sample[7]), latest)
        self.assertEqual(PROJECT_ID, sample[1])
        self.assertEqual(DEVICE_ID, sample[2])
        self.assertEqual(PROFILE_VERSION_ID, sample[3])
        self.assertEqual('msg-1', sample[4])
        self.assertEqual({'temperature': 40}, json.loads(sample[7]))
        outcome = connection.executed[-1]
        self.assertEqual('persisted', outcome[4])
        self.assertEqual([], json.loads(outcome[5]))
        self.assertEqual(result.processing_id, outcome[7])
        self.assertEqual(
            ['received', 'resolved', 'decoded', 'dedup_checked', 'validated', 'commit'],
            [stage['stage'] for stage in json.loads(outcome[8])],
        )
        self.assertEqual(RECEIVED_AT.isoformat(), json.loads(outcome[8])[0]['at'])
        self.assertNotIn(RAW.decode(), repr(connection.executed))

    async def test_same_device_and_message_id_with_same_content_is_a_duplicate(self) -> None:
        envelope = inspect_telemetry_envelope(RAW, received_at=RECEIVED_AT)
        connection = _FakeConnection(identity={'content_hash': envelope.content_hash})

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=RAW,
            retained=False,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('duplicate', result.status)
        self.assertIsNone(result.sample)
        self.assertEqual([INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)
        self.assertEqual((PROJECT_ID, DEVICE_ID, 'msg-1'), connection.fetchrow_calls[-1][1:])
        self.assertEqual('duplicate', connection.executed[-1][4])

    async def test_same_device_and_message_id_with_different_content_is_a_conflict(self) -> None:
        connection = _FakeConnection(identity={'content_hash': '0' * 64})

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=RAW,
            retained=False,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('idempotency_conflict', result.status)
        self.assertEqual(['idempotency_conflict'], [reason.code for reason in result.reasons])
        self.assertEqual([INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)

    async def test_same_message_id_on_another_device_is_a_new_identity(self) -> None:
        other_device_id = '123e4567-e89b-12d3-a456-426614174003'
        connection = _FakeConnection()

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=other_device_id,
            raw=RAW,
            retained=False,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('persisted', result.status)
        self.assertEqual((PROJECT_ID, other_device_id, 'msg-1'), connection.fetchrow_calls[-1][1:])

    async def test_schema_rejection_keeps_presence_and_records_no_raw_payload_or_sample(self) -> None:
        connection = _FakeConnection()
        raw = RAW.replace(b'40', b'200')

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=raw,
            retained=False,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('rejected', result.status)
        self.assertEqual(['above_maximum'], [reason.code for reason in result.reasons])
        self.assertEqual(MARK_DEVICE_PRESENCE_SQL, connection.presence_sql)
        self.assertEqual([INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)
        self.assertNotIn(raw.decode(), repr(connection.executed))

    async def test_device_without_profile_persists_flat_values_with_server_metadata(self) -> None:
        connection = _FakeConnection(device={
            'device_profile_version_id': None,
            'version': None,
            'definition': None,
            'received_at': RECEIVED_AT,
        })
        raw = b'{"temperature":40,"mode":"auto","enabled":true}'

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=raw,
            retained=False,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('persisted', result.status)
        self.assertEqual((), result.reasons)
        self.assertIsNotNone(result.sample)
        self.assertIsNone(result.sample.device_profile_version_id)
        self.assertEqual({'temperature': 40, 'mode': 'auto', 'enabled': True}, result.sample.values)
        self.assertEqual(RECEIVED_AT, result.sample.occurred_at)
        UUID(result.sample.message_id)
        self.assertIsNotNone(result.outcome)
        self.assertEqual('persisted', result.outcome.status)
        self.assertEqual((), result.outcome.reason_codes)
        self.assertEqual(result.sample.message_id, result.outcome.message_id)
        self.assertEqual(MARK_DEVICE_PRESENCE_SQL, connection.presence_sql)
        self.assertEqual(
            [INSERT_SAMPLE_SQL, UPSERT_LATEST_VALUES_SQL, INSERT_MESSAGE_IDENTITY_SQL, INSERT_INGEST_OUTCOME_SQL],
            connection.executed_sql,
        )
        sample = connection.executed[0]
        self.assertIsNone(sample[3])
        self.assertEqual(result.sample.message_id, sample[4])
        self.assertEqual({'temperature': 40, 'mode': 'auto', 'enabled': True}, json.loads(sample[7]))
        outcome = connection.executed[-1]
        self.assertEqual('persisted', outcome[4])
        self.assertEqual([], json.loads(outcome[5]))
        self.assertEqual(
            ['received', 'resolved', 'decoded', 'dedup_checked', 'schema_validation_skipped', 'commit'],
            [stage['stage'] for stage in result.outcome.stages],
        )
        self.assertEqual(
            ['received', 'resolved', 'decoded', 'dedup_checked', 'schema_validation_skipped', 'commit'],
            [stage['stage'] for stage in json.loads(outcome[8])],
        )

    async def test_profileless_wrapped_values_deduplicate_client_ids_by_raw_payload_hash(self) -> None:
        raw = b'{"message_id":"client-message","values":{"temperature":40}}'
        inspection = inspect_unprofiled_telemetry(raw, received_at=RECEIVED_AT)
        connection = _FakeConnection(
            device={
                'device_profile_version_id': None,
                'version': None,
                'definition': None,
                'received_at': RECEIVED_AT,
            },
            identity={'content_hash': inspection.content_hash},
        )

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=raw,
            retained=False,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('duplicate', result.status)
        self.assertEqual('client-message', result.outcome.message_id)
        self.assertEqual([INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)

    async def test_profileless_client_id_conflict_does_not_write_another_sample(self) -> None:
        connection = _FakeConnection(
            device={
                'device_profile_version_id': None,
                'version': None,
                'definition': None,
                'received_at': RECEIVED_AT,
            },
            identity={'content_hash': '0' * 64},
        )

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=b'{"message_id":"client-message","temperature":41}',
            retained=False,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('idempotency_conflict', result.status)
        self.assertEqual('client-message', result.outcome.message_id)
        self.assertEqual([INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)

    async def test_profileless_payload_without_metrics_is_rejected_without_sample_writes(self) -> None:
        connection = _FakeConnection(device={
            'device_profile_version_id': None,
            'version': None,
            'definition': None,
            'received_at': RECEIVED_AT,
        })

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=b'{"message_id":"client-message"}',
            retained=False,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('rejected', result.status)
        self.assertEqual(['invalid_payload'], [reason.code for reason in result.reasons])
        self.assertEqual([INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)

    async def test_profileless_retained_telemetry_remains_rejected(self) -> None:
        connection = _FakeConnection(device={
            'device_profile_version_id': None,
            'version': None,
            'definition': None,
            'received_at': RECEIVED_AT,
        })

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=b'{"temperature":40}',
            retained=True,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('rejected', result.status)
        self.assertEqual(['retained_message'], [reason.code for reason in result.reasons])
        self.assertEqual([INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)

    async def test_server_generated_message_ids_are_distinct_for_each_receipt(self) -> None:
        connection = _FakeConnection(device={
            'device_profile_version_id': None,
            'version': None,
            'definition': None,
            'received_at': RECEIVED_AT,
        })
        ingestor = PostgresTelemetryIngestor(_FakePool(connection))

        first = await ingestor.ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=b'{"temperature":40}',
            retained=False,
            received_wall=RECEIVED_AT,
        )
        second = await ingestor.ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=b'{"temperature":40}',
            retained=False,
            received_wall=RECEIVED_AT,
        )

        self.assertNotEqual(first.sample.message_id, second.sample.message_id)
        self.assertEqual(2, connection.executed_sql.count(INSERT_MESSAGE_IDENTITY_SQL))

    async def test_retained_telemetry_is_rejected_after_presence_is_updated(self) -> None:
        connection = _FakeConnection()

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=RAW,
            retained=True,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('rejected', result.status)
        self.assertEqual(['retained_message'], [reason.code for reason in result.reasons])
        self.assertEqual(MARK_DEVICE_PRESENCE_SQL, connection.presence_sql)
        self.assertEqual([INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)
        self.assertEqual('rejected', connection.executed[-1][4])

    async def test_unknown_or_cross_project_device_is_recorded_centrally_without_tenant_rows(self) -> None:
        other_project_row = {**BLOCKED_ROW, 'project_id': UUID(OTHER_PROJECT_ID)}
        for scope, reason in ((None, 'device_not_found'), (other_project_row, 'project_mismatch')):
            with self.subTest(reason=reason):
                connection = _FakeConnection(device_exists=False, scope=scope)

                result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
                    project_id=PROJECT_ID,
                    device_id=DEVICE_ID,
                    raw=RAW,
                    retained=False,
                    received_wall=RECEIVED_AT,
                )

                self.assertEqual('unresolved', result.status)
                self.assertIsNone(result.sample)
                self.assertEqual([INSERT_UNRESOLVED_MESSAGE_SQL], connection.executed_sql)
                processing_id, topic, recorded_reason, payload_size, stages = connection.executed[0]
                self.assertEqual(result.processing_id, processing_id)
                self.assertEqual(f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/telemetry', topic)
                self.assertEqual(reason, recorded_reason)
                self.assertEqual(len(RAW), payload_size)
                self.assertEqual(['received', 'commit'], [stage['stage'] for stage in json.loads(stages)])
                self.assertNotIn(RAW.decode(), repr(connection.executed))
                self.assertIsNone(connection.presence_sql)

    async def test_blocked_device_in_its_own_project_gets_a_tenant_owned_unresolved_outcome(self) -> None:
        # The full reason matrix is covered by test_decisions; this checks how the verdict is written.
        connection = _FakeConnection(device_exists=False, scope=BLOCKED_ROW)

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=RAW,
            retained=False,
            received_wall=RECEIVED_AT,
        )

        self.assertEqual('unresolved', result.status)
        self.assertEqual(['device_not_approved'], [rejection.code for rejection in result.reasons])
        self.assertEqual([INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)
        outcome = connection.executed[0]
        self.assertEqual(('msg-1', 'unresolved'), (outcome[3], outcome[4]))
        self.assertEqual(result.processing_id, outcome[7])
        self.assertIsNone(connection.presence_sql)
        self.assertNotIn(RAW.decode(), repr(connection.executed))

    async def test_database_failure_rolls_back_and_propagates(self) -> None:
        connection = _FakeConnection(fail_on_sql=INSERT_SAMPLE_SQL)

        with self.assertRaisesRegex(RuntimeError, 'postgres unavailable'):
            await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
                project_id=PROJECT_ID,
                device_id=DEVICE_ID,
                raw=RAW,
                retained=False,
                received_wall=RECEIVED_AT,
            )

        self.assertEqual('rollback', connection.events[-1])

    async def test_outcome_failure_rolls_back_sample_latest_and_identity_writes(self) -> None:
        connection = _FakeConnection(
            device={
                'device_profile_version_id': None,
                'version': None,
                'definition': None,
                'received_at': RECEIVED_AT,
            },
            fail_on_sql=INSERT_INGEST_OUTCOME_SQL,
        )

        with self.assertRaisesRegex(RuntimeError, 'postgres unavailable'):
            await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
                project_id=PROJECT_ID,
                device_id=DEVICE_ID,
                raw=b'{"temperature":40}',
                retained=False,
                received_wall=RECEIVED_AT,
            )

        self.assertEqual(
            [INSERT_SAMPLE_SQL, UPSERT_LATEST_VALUES_SQL, INSERT_MESSAGE_IDENTITY_SQL],
            connection.executed_sql,
        )
        self.assertEqual('rollback', connection.events[-1])


class _FakePool:
    def __init__(self, connection: _FakeConnection) -> None:
        self.connection = connection

    def acquire(self) -> _AcquiredConnection:
        return _AcquiredConnection(self.connection)


class _AcquiredConnection:
    def __init__(self, connection: _FakeConnection) -> None:
        self.connection = connection

    async def __aenter__(self) -> _FakeConnection:
        return self.connection

    async def __aexit__(self, *_args: object) -> None:
        return None


class _FakeTransaction:
    def __init__(self, connection: _FakeConnection) -> None:
        self.connection = connection

    async def __aenter__(self) -> None:
        self.connection.events.append('transaction.begin')

    async def __aexit__(self, exc_type: object, *_args: object) -> None:
        self.connection.events.append('rollback' if exc_type is not None else 'commit')


class _FakeConnection:
    def __init__(
        self,
        *,
        device: dict[str, object] | None = None,
        identity: dict[str, str] | None = None,
        fail_on_sql: str | None = None,
        device_exists: bool = True,
        scope: dict[str, object] | None = None,
    ) -> None:
        self.device = device if device is not None else {
            'device_profile_version_id': UUID(PROFILE_VERSION_ID),
            'version': 1,
            'definition': DEFINITION,
            'received_at': RECEIVED_AT,
        } if device_exists else None
        self.identity = identity
        self.scope = scope
        self.fail_on_sql = fail_on_sql
        self.events: list[str] = []
        self.executed_sql: list[str] = []
        self.executed: list[tuple[object, ...]] = []
        self.fetchrow_calls: list[tuple[object, ...]] = []
        self.presence_sql: str | None = None

    def transaction(self) -> _FakeTransaction:
        return _FakeTransaction(self)

    async def fetchrow(self, sql: str, *args: object) -> dict[str, object] | None:
        self.fetchrow_calls.append((sql, *args))
        if sql == SELECT_DEVICE_CONTEXT_SQL:
            self.events.append('device.resolve')
            return self.device
        if sql == MARK_DEVICE_PRESENCE_SQL:
            self.events.append('presence.update')
            self.presence_sql = sql
            return {
                'tenant_id': UUID(TENANT_ID),
                'project_id': UUID(PROJECT_ID),
                'device_id': UUID(DEVICE_ID),
                'connection_state': 'online',
                'last_seen_at': RECEIVED_AT,
                'updated_at': RECEIVED_AT,
            }
        if sql == SELECT_MESSAGE_IDENTITY_SQL:
            return self.identity
        if sql == SELECT_DEVICE_SCOPE_STATUS_SQL:
            return self.scope
        raise AssertionError(f'Unexpected fetchrow SQL: {sql}')

    async def fetchval(self, sql: str, *args: object) -> object:
        if sql == LOCK_MESSAGE_IDENTITY_SQL:
            return None
        raise AssertionError(f'Unexpected fetchval SQL: {sql}')

    async def execute(self, sql: str, *args: object) -> str:
        if sql == self.fail_on_sql:
            raise RuntimeError('postgres unavailable')
        self.executed_sql.append(sql)
        self.executed.append(args)
        return 'INSERT 0 1'
