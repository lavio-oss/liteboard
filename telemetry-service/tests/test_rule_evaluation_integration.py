from __future__ import annotations

import asyncio
import json
import os
import unittest
from datetime import datetime, timedelta, timezone
from uuid import UUID, uuid4

import asyncpg

from app.realtime.presence_hub import PresenceHub
from app.storage.presence_monitor import PostgresDevicePresenceMonitor
from app.storage.queries import INSERT_EVALUATION_JOB_SQL
from app.storage.rule_evaluation import PostgresRuleEvaluator
from app.storage.telemetry_ingest import PostgresTelemetryIngestor

DATABASE_URL = os.getenv('TELEMETRY_TEST_DATABASE_URL')
PROFILE_DEFINITION = {
    'strict_mode': True,
    'fields': {'temperature': {'type': 'float', 'unit': 'celsius', 'required': True}},
}
RULE_DEFINITION = {
    'field': 'temperature',
    'trigger': {'operator': 'gt', 'value': 80, 'duration_seconds': 30},
    'clear': {'operator': 'lte', 'value': 75, 'duration_seconds': 30},
    'max_sample_gap_seconds': 15,
    'severity': 'critical',
}


@unittest.skipUnless(DATABASE_URL, 'Set TELEMETRY_TEST_DATABASE_URL to a migrated disposable PostgreSQL database.')
class RuleEvaluationIntegrationTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self) -> None:
        self.pool = await asyncpg.create_pool(DATABASE_URL, min_size=1, max_size=4)
        self.tenant_id = uuid4()
        self.project_id = uuid4()
        self.other_project_id = uuid4()
        self.profile_id = uuid4()
        self.version_id = uuid4()
        self.asset_id = uuid4()
        self.device_id = uuid4()
        self.rule_id = uuid4()
        self.revision_id = uuid4()
        # Starts in the past so controlled received_at values never land in the future.
        self.t0 = datetime.now(timezone.utc).replace(microsecond=0) - timedelta(hours=1)
        self.sequence = 0
        now = datetime.now(timezone.utc).replace(tzinfo=None)

        async with self.pool.acquire() as connection:
            async with connection.transaction():
                await connection.execute(
                    "INSERT INTO tenants (id, name, code, status, created_at, updated_at) VALUES ($1, 'IT5', $2, 'active', $3, $3)",
                    self.tenant_id, f'it5-{self.tenant_id.hex}', now,
                )
                for project_id in (self.project_id, self.other_project_id):
                    await connection.execute(
                        "INSERT INTO projects (id, tenant_id, name, status, created_at, updated_at) VALUES ($1, $2, 'IT5', 'active', $3, $3)",
                        project_id, self.tenant_id, now,
                    )
                await connection.execute(
                    "INSERT INTO device_profiles (id, project_id, code, name, created_at, updated_at) VALUES ($1, $2, $3, 'Motor', $4, $4)",
                    self.profile_id, self.project_id, f'it5-{self.profile_id.hex[:12]}', now,
                )
                await connection.execute(
                    "INSERT INTO device_profile_versions (id, project_id, device_profile_id, version, schema_code, definition, created_at, updated_at) VALUES ($1, $2, $3, 1, $4, $5::jsonb, $6, $6)",
                    self.version_id, self.project_id, self.profile_id, f'it5-{self.version_id.hex[:12]}', json.dumps(PROFILE_DEFINITION), now,
                )
                await connection.execute(
                    "INSERT INTO assets (id, project_id, asset_type, name, code, status, created_at, updated_at) VALUES ($1, $2, 'equipment', 'Motor A', $3, 'active', $4, $4)",
                    self.asset_id, self.project_id, f'it5-{self.asset_id.hex[:12]}', now,
                )
                await connection.execute(
                    "INSERT INTO devices (id, project_id, equipment_asset_id, device_profile_version_id, name, code, status, approval_status, connection_state, last_seen_at, created_at, updated_at) VALUES ($1, $2, $3, $4, 'IT5', $5, 'active', 'approved', 'online', CURRENT_TIMESTAMP, $6, $6)",
                    self.device_id, self.project_id, self.asset_id, self.version_id, f'it5-{self.device_id.hex[:12]}', now,
                )
                await self._insert_rule(connection, self.rule_id, self.revision_id, self.project_id, self.profile_id, RULE_DEFINITION)

        self.evaluator = PostgresRuleEvaluator(self.pool, poll_seconds=0.01)

    async def asyncTearDown(self) -> None:
        async with self.pool.acquire() as connection:
            await connection.execute('DELETE FROM tenants WHERE id = $1::uuid', self.tenant_id)
        await self.pool.close()

    async def test_ingest_queues_a_job_with_the_equipment_snapshot(self) -> None:
        ingestor = PostgresTelemetryIngestor(self.pool)
        result = await ingestor.ingest(
            project_id=str(self.project_id),
            device_id=str(self.device_id),
            raw=json.dumps({
                'message_id': 'queued-1',
                'schema_version': 1,
                'occurred_at': datetime.now(timezone.utc).isoformat(),
                'values': {'temperature': 85},
            }).encode(),
            retained=False,
            received_wall=datetime.now(timezone.utc),
        )

        self.assertEqual('persisted', result.status)
        job = await self.pool.fetchrow(
            'SELECT equipment_asset_id, device_profile_version_id, evaluated_at FROM telemetry_evaluation_jobs WHERE sample_id = $1::uuid',
            result.sample.id,
        )
        self.assertEqual(self.asset_id, job['equipment_asset_id'])
        self.assertEqual(self.version_id, job['device_profile_version_id'])
        self.assertIsNone(job['evaluated_at'])

    async def test_sustained_breach_opens_exactly_one_critical_alert_and_outbox_event(self) -> None:
        for seconds in (0, 10, 20, 30, 40):
            await self._queue_sample(seconds, 85)

        await self._drain()

        alerts = await self._alerts()
        self.assertEqual(1, len(alerts))
        alert = alerts[0]
        self.assertEqual('critical', alert['severity'])
        self.assertEqual(self.asset_id, alert['equipment_asset_id'])
        self.assertEqual(self.revision_id, alert['alert_rule_revision_id'])
        self.assertEqual(self.t0 + timedelta(seconds=30), alert['opened_at'])
        self.assertEqual(self.t0 + timedelta(seconds=40), alert['last_seen_at'])
        self.assertIsNone(alert['cleared_at'])
        outbox = await self._outbox()
        self.assertEqual(['alert.opened'], [row['event_type'] for row in outbox])
        payload = json.loads(outbox[0]['payload'])
        self.assertEqual(str(self.tenant_id), payload['tenant_id'])
        self.assertEqual(str(self.asset_id), payload['alert']['equipment_asset_id'])
        self.assertEqual(0, await self._pending_jobs())

    async def test_replaying_evaluated_jobs_does_not_duplicate_alerts_or_outbox(self) -> None:
        for seconds in (0, 10, 20, 30):
            await self._queue_sample(seconds, 85)
        await self._drain()

        await self.pool.execute('UPDATE telemetry_evaluation_jobs SET evaluated_at = NULL WHERE device_id = $1::uuid', self.device_id)
        await self._drain()

        self.assertEqual(1, len(await self._alerts()))
        self.assertEqual(1, len(await self._outbox()))
        self.assertEqual(1, await self.pool.fetchval('SELECT COUNT(*) FROM alert_events WHERE project_id = $1::uuid', self.project_id))

    async def test_concurrent_evaluators_do_not_duplicate_alerts(self) -> None:
        for seconds in range(0, 61, 5):
            await self._queue_sample(seconds, 85)
        other = PostgresRuleEvaluator(self.pool, poll_seconds=0.01, batch_size=3)
        self.evaluator = PostgresRuleEvaluator(self.pool, poll_seconds=0.01, batch_size=3)

        for _ in range(10):
            await asyncio.gather(self.evaluator.run_once(), other.run_once())

        self.assertEqual(0, await self._pending_jobs())
        self.assertEqual(1, len(await self._alerts()))
        self.assertEqual(1, len(await self._outbox()))

    async def test_failure_mid_transaction_rolls_back_and_retry_produces_one_alert(self) -> None:
        for seconds in (0, 10, 20, 30):
            await self._queue_sample(seconds, 85)
        # Simulates a worker crash after the alert insert: the outbox insert fails.
        await self.pool.execute(
            "CREATE OR REPLACE FUNCTION it5_fail_outbox() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'it5 outbox failure'; END $$",
        )
        trigger = f'it5_fail_outbox_{self.project_id.hex}'
        await self.pool.execute(f'CREATE TRIGGER {trigger} BEFORE INSERT ON outbox_events FOR EACH ROW EXECUTE FUNCTION it5_fail_outbox()')
        try:
            with self.assertRaises(asyncpg.RaiseError):
                await self.evaluator.run_once()
        finally:
            await self.pool.execute(f'DROP TRIGGER {trigger} ON outbox_events')

        self.assertEqual([], await self._alerts())
        self.assertEqual(4, await self._pending_jobs())

        await self._drain()

        self.assertEqual(1, len(await self._alerts()))
        self.assertEqual(1, len(await self._outbox()))

    async def test_database_allows_only_one_active_alert_per_device_and_rule(self) -> None:
        for seconds in (0, 10, 20, 30):
            await self._queue_sample(seconds, 85)
        await self._drain()

        with self.assertRaises(asyncpg.UniqueViolationError):
            await self.pool.execute(
                "INSERT INTO alerts (id, project_id, device_id, alert_rule_id, alert_rule_revision_id, severity, opened_at, last_seen_at, evidence) VALUES ($1, $2, $3, $4, $5, 'critical', now(), now(), '{}')",
                uuid4(), self.project_id, self.device_id, self.rule_id, self.revision_id,
            )

    async def test_recovery_clears_and_a_new_breach_opens_a_new_alert(self) -> None:
        series = [(0, 85), (10, 85), (20, 85), (30, 85), (40, 78), (50, 70), (60, 70), (70, 70), (80, 70),
                  (90, 85), (100, 85), (110, 85), (120, 85)]
        for seconds, value in series:
            await self._queue_sample(seconds, value)

        await self._drain()

        alerts = await self._alerts()
        self.assertEqual(2, len(alerts))
        self.assertEqual('condition_cleared', alerts[0]['clear_reason'])
        self.assertEqual(self.t0 + timedelta(seconds=80), alerts[0]['cleared_at'])
        self.assertIsNone(alerts[1]['cleared_at'])
        self.assertEqual(
            ['alert.opened', 'alert.cleared', 'alert.opened'],
            [row['event_type'] for row in await self._outbox()],
        )

    async def test_offline_clears_once_and_backlog_cannot_reopen(self) -> None:
        for seconds in (0, 10, 20, 30):
            await self._queue_sample(seconds, 85)
        await self._drain()
        await self.pool.execute(
            "UPDATE devices SET last_seen_at = CURRENT_TIMESTAMP - INTERVAL '5 minutes' WHERE id = $1::uuid",
            self.device_id,
        )
        monitor = PostgresDevicePresenceMonitor(self.pool, PresenceHub())

        await monitor.refresh()
        await monitor.refresh()

        alerts = await self._alerts()
        self.assertEqual('device_offline', alerts[0]['clear_reason'])
        self.assertEqual(['alert.opened', 'alert.cleared'], [row['event_type'] for row in await self._outbox()])

        # Samples received before the offline transition are stale and cannot reopen the alert.
        for seconds in (40, 50, 60, 70):
            await self._queue_sample(seconds, 85)
        await self._drain()
        self.assertEqual(1, len(await self._alerts()))

        # After reconnecting, a full trigger window is required again.
        now = datetime.now(timezone.utc) + timedelta(seconds=1)
        for offset in (0, 10, 20):
            await self._queue_sample_at(now + timedelta(seconds=offset), 85)
        await self._drain()
        self.assertEqual(1, len(await self._alerts()))
        await self._queue_sample_at(now + timedelta(seconds=30), 85)
        await self._drain()
        self.assertEqual(2, len(await self._alerts()))

    async def test_rule_of_another_project_is_not_applied(self) -> None:
        other_profile_id = uuid4()
        other_version_id = uuid4()
        other_device_id = uuid4()
        now = datetime.now(timezone.utc).replace(tzinfo=None)
        async with self.pool.acquire() as connection:
            await connection.execute(
                "INSERT INTO device_profiles (id, project_id, code, name, created_at, updated_at) VALUES ($1, $2, $3, 'Motor', $4, $4)",
                other_profile_id, self.other_project_id, f'it5-{other_profile_id.hex[:12]}', now,
            )
            await connection.execute(
                "INSERT INTO device_profile_versions (id, project_id, device_profile_id, version, schema_code, definition, created_at, updated_at) VALUES ($1, $2, $3, 1, $4, $5::jsonb, $6, $6)",
                other_version_id, self.other_project_id, other_profile_id, f'it5-{other_version_id.hex[:12]}', json.dumps(PROFILE_DEFINITION), now,
            )
            await connection.execute(
                "INSERT INTO devices (id, project_id, device_profile_version_id, name, code, status, approval_status, connection_state, created_at, updated_at) VALUES ($1, $2, $3, 'Other', $4, 'active', 'approved', 'online', $5, $5)",
                other_device_id, self.other_project_id, other_version_id, f'it5-{other_device_id.hex[:12]}', now,
            )
            ingest_job = await connection.fetchval(
                """
                INSERT INTO telemetry_samples (id, project_id, device_id, device_profile_version_id, message_id, occurred_at, received_at, "values", status)
                VALUES ($1, $2, $3, $4, 'other-1', $5, $5, '{"temperature": 99}', 'persisted')
                RETURNING id
                """,
                uuid4(), self.other_project_id, other_device_id, other_version_id, self.t0,
            )
            status = await connection.execute(
                INSERT_EVALUATION_JOB_SQL,
                str(ingest_job), str(self.other_project_id), str(other_device_id), str(other_version_id), None, self.t0,
            )

        self.assertEqual('INSERT 0 0', status)

    async def _insert_rule(self, connection, rule_id: UUID, revision_id: UUID, project_id: UUID, profile_id: UUID, definition: dict) -> None:
        await connection.execute(
            "INSERT INTO alert_rules (id, project_id, device_profile_id, code, name, status, created_at, updated_at) VALUES ($1, $2, $3, $4, 'High temperature', 'active', now(), now())",
            rule_id, project_id, profile_id, f'high-{rule_id.hex[:8]}',
        )
        await connection.execute(
            'INSERT INTO alert_rule_revisions (id, project_id, alert_rule_id, revision, definition) VALUES ($1, $2, $3, 1, $4::jsonb)',
            revision_id, project_id, rule_id, json.dumps(definition),
        )
        await connection.execute('UPDATE alert_rules SET current_revision_id = $2 WHERE id = $1', rule_id, revision_id)

    async def _queue_sample(self, seconds: float, temperature: float) -> None:
        await self._queue_sample_at(self.t0 + timedelta(seconds=seconds), temperature)

    async def _queue_sample_at(self, received_at: datetime, temperature: float) -> None:
        self.sequence += 1
        sample_id = uuid4()
        async with self.pool.acquire() as connection:
            async with connection.transaction():
                await connection.execute(
                    """
                    INSERT INTO telemetry_samples (id, project_id, device_id, device_profile_version_id, message_id, occurred_at, received_at, "values", status)
                    VALUES ($1, $2, $3, $4, $5, $6, $6, $7::jsonb, 'persisted')
                    """,
                    sample_id, self.project_id, self.device_id, self.version_id, f'm-{self.sequence}', received_at,
                    json.dumps({'temperature': temperature}),
                )
                await connection.execute(
                    'INSERT INTO telemetry_evaluation_jobs (sample_id, project_id, device_id, device_profile_version_id, equipment_asset_id, received_at) VALUES ($1, $2, $3, $4, $5, $6)',
                    sample_id, self.project_id, self.device_id, self.version_id, self.asset_id, received_at,
                )

    async def _drain(self) -> None:
        for _ in range(50):
            if await self.evaluator.run_once() == 0:
                return
        raise AssertionError('Evaluation did not drain.')

    async def _alerts(self) -> list[asyncpg.Record]:
        return await self.pool.fetch('SELECT * FROM alerts WHERE device_id = $1::uuid ORDER BY opened_at', self.device_id)

    async def _outbox(self) -> list[asyncpg.Record]:
        return await self.pool.fetch(
            'SELECT event_type, payload FROM outbox_events WHERE project_id = $1::uuid ORDER BY created_at, payload->>\'occurred_at\'',
            self.project_id,
        )

    async def _pending_jobs(self) -> int:
        return await self.pool.fetchval(
            'SELECT COUNT(*) FROM telemetry_evaluation_jobs WHERE device_id = $1::uuid AND evaluated_at IS NULL',
            self.device_id,
        )
