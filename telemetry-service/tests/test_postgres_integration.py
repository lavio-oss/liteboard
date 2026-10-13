from __future__ import annotations

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
    'fields': {'temperature': {'type': 'float', 'unit': 'celsius', 'required': True, 'max': 150}},
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

    async def test_inactive_or_unapproved_scope_creates_no_outcome(self) -> None:
        async with self.pool.acquire() as connection:
            await connection.execute("UPDATE devices SET approval_status = 'pending' WHERE id = $1::uuid", self.device_ids[0])

        result = await self._ingest(self.device_ids[0], self._payload('pending-device', 40))

        self.assertEqual('unresolved', result.status)
        async with self.pool.acquire() as connection:
            outcome_count = await connection.fetchval(
                'SELECT COUNT(*) FROM telemetry_ingest_outcomes WHERE device_id = $1::uuid',
                self.device_ids[0],
            )
            presence = await connection.fetchrow(
                'SELECT connection_state, last_seen_at FROM devices WHERE id = $1::uuid',
                self.device_ids[0],
            )

        self.assertEqual(0, outcome_count)
        self.assertEqual('offline', presence['connection_state'])
        self.assertIsNone(presence['last_seen_at'])

    async def _ingest(self, device_id: object, raw: bytes):
        return await self.ingestor.ingest(
            project_id=str(self.project_id),
            device_id=str(device_id),
            raw=raw,
            retained=False,
        )

    @staticmethod
    def _payload(message_id: str, temperature: int) -> bytes:
        return json.dumps(
            {
                'message_id': message_id,
                'schema_version': 1,
                'occurred_at': datetime.now(timezone.utc).isoformat(),
                'values': {'temperature': temperature},
            },
            separators=(',', ':'),
        ).encode()
