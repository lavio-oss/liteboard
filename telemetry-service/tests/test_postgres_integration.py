from __future__ import annotations

import asyncio
import json
import os
import unittest
from datetime import datetime, timezone
from uuid import uuid4

import asyncpg

from app.storage.telemetry_ingest import PostgresTelemetryIngestor

DATABASE_URL = os.getenv('TELEMETRY_TEST_DATABASE_URL')
PROFILE_DEFINITION = {
    'strict_mode': True,
    'fields': {
        'temperature': {'type': 'float', 'unit': 'celsius', 'required': True, 'max': 150},
        'rpm': {'type': 'integer', 'unit': 'rpm', 'required': False},
    },
}


@unittest.skipUnless(DATABASE_URL, 'Set TELEMETRY_TEST_DATABASE_URL to a migrated disposable PostgreSQL database.')
class PostgresTelemetryIntegrationTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self) -> None:
        self.pool = await asyncpg.create_pool(DATABASE_URL, min_size=1, max_size=3)
        self.tenant_id = uuid4()
        self.project_id = uuid4()
        self.profile_id = uuid4()
        self.profile_version_id = uuid4()
        self.device_ids = (uuid4(), uuid4())
        now = datetime.now(timezone.utc).replace(tzinfo=None)

        async with self.pool.acquire() as connection:
            async with connection.transaction():
                await connection.execute(
                    "INSERT INTO tenants (id, name, code, status, created_at, updated_at) VALUES ($1, 'IT3', $2, 'active', $3, $3)",
                    self.tenant_id,
                    f'it3-{self.tenant_id.hex}',
                    now,
                )
                await connection.execute(
                    "INSERT INTO projects (id, tenant_id, name, status, created_at, updated_at) VALUES ($1, $2, 'IT3', 'active', $3, $3)",
                    self.project_id,
                    self.tenant_id,
                    now,
                )
                await connection.execute(
                    "INSERT INTO device_profiles (id, project_id, code, name, created_at, updated_at) VALUES ($1, $2, $3, 'IT3', $4, $4)",
                    self.profile_id,
                    self.project_id,
                    f'it3-{self.profile_id.hex[:12]}',
                    now,
                )
                await connection.execute(
                    "INSERT INTO device_profile_versions (id, project_id, device_profile_id, version, schema_code, definition, created_at, updated_at) VALUES ($1, $2, $3, 1, $4, $5::jsonb, $6, $6)",
                    self.profile_version_id,
                    self.project_id,
                    self.profile_id,
                    f'it3-{self.profile_version_id.hex[:12]}-v1',
                    json.dumps(PROFILE_DEFINITION),
                    now,
                )
                for index, device_id in enumerate(self.device_ids):
                    await connection.execute(
                        "INSERT INTO devices (id, project_id, device_profile_version_id, name, code, status, approval_status, connection_state, created_at, updated_at) VALUES ($1, $2, $3, $4, $5, 'active', 'approved', 'offline', $6, $6)",
                        device_id,
                        self.project_id,
                        self.profile_version_id,
                        f'IT3 {index}',
                        f'it3-{device_id.hex[:12]}',
                        now,
                    )

        self.ingestor = PostgresTelemetryIngestor(self.pool)

    async def asyncTearDown(self) -> None:
        async with self.pool.acquire() as connection:
            await connection.execute('DELETE FROM tenants WHERE id = $1::uuid', self.tenant_id)
        await self.pool.close()

    async def test_persist_duplicate_conflict_and_cross_device_identity(self) -> None:
        raw = self._payload('same-message', 40)

        first = await self._ingest(self.device_ids[0], raw)
        duplicate = await self._ingest(self.device_ids[0], raw)
        conflict = await self._ingest(self.device_ids[0], self._payload('same-message', 41))
        other_device = await self._ingest(self.device_ids[1], raw)

        self.assertEqual('persisted', first.status)
        self.assertEqual('duplicate', duplicate.status)
        self.assertEqual('idempotency_conflict', conflict.status)
        self.assertEqual('persisted', other_device.status)
        async with self.pool.acquire() as connection:
            sample_count = await connection.fetchval(
                'SELECT COUNT(*) FROM telemetry_samples WHERE project_id = $1::uuid',
                self.project_id,
            )
            outcomes = await connection.fetch(
                'SELECT status, COUNT(*) AS count FROM telemetry_ingest_outcomes WHERE project_id = $1::uuid GROUP BY status',
                self.project_id,
            )

        self.assertEqual(2, sample_count)
        self.assertEqual(
            {'persisted': 2, 'duplicate': 1, 'idempotency_conflict': 1},
            {row['status']: row['count'] for row in outcomes},
        )

    async def test_outcome_keeps_processing_id_and_stage_timeline_including_broadcast(self) -> None:
        result = await self._ingest(self.device_ids[0], self._payload('traced-message', 40))
        await self.ingestor.record_broadcast(result.processing_id, 2)

        async with self.pool.acquire() as connection:
            stages = await connection.fetchval(
                'SELECT stages FROM telemetry_ingest_outcomes WHERE processing_id = $1::uuid',
                result.processing_id,
            )

        timeline = json.loads(stages)
        self.assertEqual(
            ['received', 'resolved', 'decoded', 'dedup_checked', 'validated', 'commit', 'broadcast'],
            [stage['stage'] for stage in timeline],
        )
        self.assertEqual(2, timeline[-1]['subscribers'])

    async def test_rejection_updates_presence_without_storing_the_payload(self) -> None:
        raw = self._payload('schema-reject', 200)

        result = await self._ingest(self.device_ids[0], raw)

        self.assertEqual('rejected', result.status)
        async with self.pool.acquire() as connection:
            device = await connection.fetchrow(
                'SELECT connection_state, last_seen_at FROM devices WHERE id = $1::uuid',
                self.device_ids[0],
            )
            outcome = await connection.fetchrow(
                "SELECT status, reason_codes::text FROM telemetry_ingest_outcomes WHERE device_id = $1::uuid",
                self.device_ids[0],
            )
            sample_count = await connection.fetchval(
                'SELECT COUNT(*) FROM telemetry_samples WHERE device_id = $1::uuid',
                self.device_ids[0],
            )

        self.assertEqual('online', device['connection_state'])
        self.assertIsNotNone(device['last_seen_at'])
        self.assertEqual('rejected', outcome['status'])
        self.assertIn('above_maximum', outcome['reason_codes'])
        self.assertEqual(0, sample_count)
        self.assertNotIn(raw.decode(), outcome['reason_codes'])

    async def test_device_without_profile_persists_metrics_and_updates_latest_values(self) -> None:
        raw = b'{"temperature":40,"mode":"auto"}'
        async with self.pool.acquire() as connection:
            await connection.execute(
                'UPDATE devices SET device_profile_version_id = NULL WHERE id = $1::uuid',
                self.device_ids[0],
            )

        result = await self._ingest(self.device_ids[0], raw)

        self.assertEqual('persisted', result.status)
        self.assertIsNotNone(result.sample)
        self.assertIsNone(result.sample.device_profile_version_id)
        self.assertEqual({'temperature': 40, 'mode': 'auto'}, result.sample.values)
        async with self.pool.acquire() as connection:
            outcome = await connection.fetchrow(
                'SELECT status, message_id, reason_codes::text, stages FROM telemetry_ingest_outcomes WHERE processing_id = $1::uuid',
                result.processing_id,
            )
            sample = await connection.fetchrow(
                'SELECT device_profile_version_id, occurred_at, received_at, "values"::text FROM telemetry_samples WHERE device_id = $1::uuid AND message_id = $2',
                self.device_ids[0],
                result.sample.message_id,
            )
            latest = await connection.fetchrow(
                "SELECT value::text FROM telemetry_latest_values WHERE device_id = $1::uuid AND metric = 'temperature'",
                self.device_ids[0],
            )

        self.assertEqual('persisted', outcome['status'])
        self.assertEqual(result.sample.message_id, outcome['message_id'])
        self.assertEqual('[]', outcome['reason_codes'])
        self.assertEqual(
            ['received', 'resolved', 'decoded', 'dedup_checked', 'schema_validation_skipped', 'commit'],
            [stage['stage'] for stage in json.loads(outcome['stages'])],
        )
        self.assertIsNone(sample['device_profile_version_id'])
        self.assertEqual({'temperature': 40, 'mode': 'auto'}, json.loads(sample['values']))
        self.assertEqual(sample['received_at'], sample['occurred_at'])
        self.assertEqual('40', latest['value'])

    async def test_pending_device_gets_an_unresolved_outcome_without_presence_or_sample(self) -> None:
        async with self.pool.acquire() as connection:
            await connection.execute("UPDATE devices SET approval_status = 'pending' WHERE id = $1::uuid", self.device_ids[0])

        result = await self._ingest(self.device_ids[0], self._payload('pending-device', 40))

        self.assertEqual('unresolved', result.status)
        async with self.pool.acquire() as connection:
            outcome = await connection.fetchrow(
                'SELECT status, message_id, reason_codes::text, processing_id::text FROM telemetry_ingest_outcomes WHERE device_id = $1::uuid',
                self.device_ids[0],
            )
            presence = await connection.fetchrow(
                'SELECT connection_state, last_seen_at FROM devices WHERE id = $1::uuid',
                self.device_ids[0],
            )
            sample_count = await connection.fetchval(
                'SELECT COUNT(*) FROM telemetry_samples WHERE device_id = $1::uuid',
                self.device_ids[0],
            )

        self.assertEqual(('unresolved', 'pending-device'), (outcome['status'], outcome['message_id']))
        self.assertIn('device_not_approved', outcome['reason_codes'])
        self.assertEqual(result.processing_id, outcome['processing_id'])
        self.assertEqual(0, sample_count)
        self.assertEqual('offline', presence['connection_state'])
        self.assertIsNone(presence['last_seen_at'])

    async def test_unknown_device_is_recorded_centrally_and_creates_no_tenant_row(self) -> None:
        unknown_device_id = uuid4()

        result = await self._ingest(unknown_device_id, self._payload('ghost', 40))

        async with self.pool.acquire() as connection:
            unresolved = await connection.fetchrow(
                'SELECT topic, reason, payload_size FROM telemetry_unresolved_messages WHERE processing_id = $1::uuid',
                result.processing_id,
            )
            outcome_count = await connection.fetchval(
                'SELECT COUNT(*) FROM telemetry_ingest_outcomes WHERE project_id = $1::uuid',
                self.project_id,
            )
            await connection.execute(
                'DELETE FROM telemetry_unresolved_messages WHERE processing_id = $1::uuid',
                result.processing_id,
            )

        self.assertEqual('unresolved', result.status)
        self.assertEqual(f'liteboard/{self.project_id}/devices/{unknown_device_id}/telemetry', unresolved['topic'])
        self.assertEqual('device_not_found', unresolved['reason'])
        self.assertEqual(0, outcome_count)

    async def test_concurrent_messages_from_one_device_do_not_deadlock(self) -> None:
        results = await asyncio.gather(
            *(self._ingest(self.device_ids[0], self._payload(f'concurrent-{index}', 40)) for index in range(12)),
        )

        self.assertEqual(['persisted'] * 12, [result.status for result in results])

    async def test_sub_second_event_times_keep_order_for_latest_values(self) -> None:
        await self._ingest(self.device_ids[0], self._payload('later', 60, occurred_at='2026-10-13T10:00:00.900000+00:00'))
        await self._ingest(self.device_ids[0], self._payload('earlier', 10, occurred_at='2026-10-13T10:00:00.100000+00:00'))

        async with self.pool.acquire() as connection:
            latest = await connection.fetchrow(
                "SELECT message_id, occurred_at FROM telemetry_latest_values WHERE device_id = $1::uuid AND metric = 'temperature'",
                self.device_ids[0],
            )

        self.assertEqual('later', latest['message_id'])
        self.assertEqual(900000, latest['occurred_at'].microsecond)

    async def test_latest_values_keep_each_metric_by_event_time(self) -> None:
        await self._ingest(self.device_ids[0], self._payload('new', 50, rpm=1200, occurred_at='2026-10-13T10:00:05+00:00'))
        await self._ingest(self.device_ids[0], self._payload('late', 10, rpm=900, occurred_at='2026-10-13T09:00:00+00:00'))
        await self._ingest(self.device_ids[0], self._payload('newest', 60, occurred_at='2026-10-13T10:00:10+00:00'))

        async with self.pool.acquire() as connection:
            rows = await connection.fetch(
                'SELECT metric, value::text, message_id FROM telemetry_latest_values WHERE device_id = $1::uuid ORDER BY metric',
                self.device_ids[0],
            )

        self.assertEqual(
            [('rpm', '1200', 'new'), ('temperature', '60', 'newest')],
            [(row['metric'], row['value'], row['message_id']) for row in rows],
        )

    async def _ingest(self, device_id: object, raw: bytes):
        return await self.ingestor.ingest(
            project_id=str(self.project_id),
            device_id=str(device_id),
            raw=raw,
            retained=False,
            received_wall=datetime.now(timezone.utc),
        )

    @staticmethod
    def _payload(message_id: str, temperature: int, *, rpm: int | None = None, occurred_at: str | None = None) -> bytes:
        return json.dumps(
            {
                'message_id': message_id,
                'schema_version': 1,
                'occurred_at': occurred_at or datetime.now(timezone.utc).isoformat(),
                'values': {'temperature': temperature} | ({} if rpm is None else {'rpm': rpm}),
            },
            separators=(',', ':'),
        ).encode()
