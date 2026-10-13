from __future__ import annotations

import json
import unittest
from datetime import datetime, timezone
from uuid import UUID

from app.storage.telemetry_ingest import (
    INSERT_INGEST_OUTCOME_SQL,
    INSERT_MESSAGE_IDENTITY_SQL,
    INSERT_SAMPLE_SQL,
    LOCK_MESSAGE_IDENTITY_SQL,
    MARK_DEVICE_PRESENCE_SQL,
    SELECT_DEVICE_CONTEXT_SQL,
    SELECT_MESSAGE_IDENTITY_SQL,
    IngestResult,
    PostgresTelemetryIngestor,
)
from app.telemetry.envelope import inspect_telemetry_envelope

PROJECT_ID = '123e4567-e89b-12d3-a456-426614174000'
DEVICE_ID = '123e4567-e89b-12d3-a456-426614174001'
PROFILE_VERSION_ID = '123e4567-e89b-12d3-a456-426614174002'
RECEIVED_AT = datetime(2026, 10, 11, 4, 0, tzinfo=timezone.utc)
DEFINITION = {
    'strict_mode': True,
    'fields': {'temperature': {'type': 'float', 'unit': 'celsius', 'required': True, 'max': 150}},
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
        )

        self.assertEqual(IngestResult('persisted'), result)
        self.assertEqual('commit', connection.events[-1])
        self.assertEqual(1, connection.events.count('transaction.begin'))
        self.assertEqual(MARK_DEVICE_PRESENCE_SQL, connection.presence_sql)
        self.assertEqual([INSERT_SAMPLE_SQL, INSERT_MESSAGE_IDENTITY_SQL, INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)
        sample = connection.executed[0]
        self.assertEqual(PROJECT_ID, sample[1])
        self.assertEqual(DEVICE_ID, sample[2])
        self.assertEqual(PROFILE_VERSION_ID, sample[3])
        self.assertEqual('msg-1', sample[4])
        self.assertEqual({'temperature': 40}, json.loads(sample[7]))
        outcome = connection.executed[-1]
        self.assertEqual('persisted', outcome[4])
        self.assertEqual([], json.loads(outcome[5]))
        self.assertNotIn(RAW.decode(), repr(connection.executed))

    async def test_same_device_and_message_id_with_same_content_is_a_duplicate(self) -> None:
        envelope = inspect_telemetry_envelope(RAW, received_at=RECEIVED_AT)
        connection = _FakeConnection(identity={'content_hash': envelope.content_hash})

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=RAW,
            retained=False,
        )

        self.assertEqual(IngestResult('duplicate'), result)
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
        )

        self.assertEqual(IngestResult('persisted'), result)
        self.assertEqual((PROJECT_ID, other_device_id, 'msg-1'), connection.fetchrow_calls[-1][1:])

    async def test_schema_rejection_keeps_presence_and_records_no_raw_payload_or_sample(self) -> None:
        connection = _FakeConnection()
        raw = RAW.replace(b'40', b'200')

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=raw,
            retained=False,
        )

        self.assertEqual('rejected', result.status)
        self.assertEqual(['above_maximum'], [reason.code for reason in result.reasons])
        self.assertEqual(MARK_DEVICE_PRESENCE_SQL, connection.presence_sql)
        self.assertEqual([INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)
        self.assertNotIn(raw.decode(), repr(connection.executed))

    async def test_retained_telemetry_is_rejected_after_presence_is_updated(self) -> None:
        connection = _FakeConnection()

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=RAW,
            retained=True,
        )

        self.assertEqual('rejected', result.status)
        self.assertEqual(['retained_message'], [reason.code for reason in result.reasons])
        self.assertEqual(MARK_DEVICE_PRESENCE_SQL, connection.presence_sql)
        self.assertEqual([INSERT_INGEST_OUTCOME_SQL], connection.executed_sql)
        self.assertEqual('rejected', connection.executed[-1][4])

    async def test_unresolved_device_creates_no_tenant_owned_rows(self) -> None:
        connection = _FakeConnection(device_exists=False)

        result = await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=RAW,
            retained=False,
        )

        self.assertEqual(IngestResult('unresolved'), result)
        self.assertEqual([], connection.executed_sql)
        self.assertEqual(['transaction.begin', 'device.resolve', 'commit'], connection.events)

    async def test_database_failure_rolls_back_and_propagates(self) -> None:
        connection = _FakeConnection(fail_on_sql=INSERT_SAMPLE_SQL)

        with self.assertRaisesRegex(RuntimeError, 'postgres unavailable'):
            await PostgresTelemetryIngestor(_FakePool(connection)).ingest(
                project_id=PROJECT_ID,
                device_id=DEVICE_ID,
                raw=RAW,
                retained=False,
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
    ) -> None:
        self.device = device if device is not None else {
            'device_profile_version_id': UUID(PROFILE_VERSION_ID),
            'version': 1,
            'definition': DEFINITION,
            'received_at': RECEIVED_AT,
        } if device_exists else None
        self.identity = identity
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
        if sql == SELECT_MESSAGE_IDENTITY_SQL:
            return self.identity
        raise AssertionError(f'Unexpected fetchrow SQL: {sql}')

    async def fetchval(self, sql: str, *args: object) -> object:
        if sql == MARK_DEVICE_PRESENCE_SQL:
            self.events.append('presence.update')
            self.presence_sql = sql
            return UUID(DEVICE_ID)
        if sql == LOCK_MESSAGE_IDENTITY_SQL:
            return None
        raise AssertionError(f'Unexpected fetchval SQL: {sql}')

    async def execute(self, sql: str, *args: object) -> str:
        if sql == self.fail_on_sql:
            raise RuntimeError('postgres unavailable')
        self.executed_sql.append(sql)
        self.executed.append(args)
        return 'INSERT 0 1'
