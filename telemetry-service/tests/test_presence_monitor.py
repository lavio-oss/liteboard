from __future__ import annotations

import json
import unittest
from collections.abc import Callable
from datetime import datetime, timedelta, timezone

from app.realtime.presence_hub import PresenceHub
from app.storage.presence import DevicePresence
from app.storage.presence_monitor import PostgresDevicePresenceMonitor, connection_state_for_age
from app.storage.queries import (
    CLEAR_OFFLINE_ALERTS_SQL,
    INSERT_ALERT_EVENT_SQL,
    INSERT_OUTBOX_EVENT_SQL,
    RESET_OFFLINE_EVALUATION_STATES_SQL,
    SELECT_ALERT_SNAPSHOT_SQL,
    UPDATE_STALE_DEVICE_PRESENCE_SQL,
)

TENANT_ID = '123e4567-e89b-12d3-a456-426614174010'
OTHER_TENANT_ID = '123e4567-e89b-12d3-a456-426614174011'
PROJECT_ID = '123e4567-e89b-12d3-a456-426614174000'
DEVICE_ID = '123e4567-e89b-12d3-a456-426614174001'
UPDATED_AT = datetime(2026, 10, 15, 10, 0, tzinfo=timezone.utc)


class ConnectionStateForAgeTest(unittest.TestCase):
    def test_classifies_presence_at_the_30_and_90_second_boundaries(self) -> None:
        cases = (
            (None, 'offline'),
            (timedelta(seconds=30), 'online'),
            (timedelta(seconds=30, microseconds=1), 'unstable'),
            (timedelta(seconds=90), 'unstable'),
            (timedelta(seconds=90, microseconds=1), 'offline'),
            (timedelta(seconds=0), 'online'),
        )

        for age, expected in cases:
            with self.subTest(age=age):
                self.assertEqual(expected, connection_state_for_age(age))


class PostgresDevicePresenceMonitorTest(unittest.IsolatedAsyncioTestCase):
    async def test_publishes_tenant_scoped_transitions_only_after_database_commit(self) -> None:
        committed = False

        class CommitCheckingHub(PresenceHub):
            def publish_device_presence(self, presence: DevicePresence) -> int:
                if not committed:
                    raise AssertionError('Presence transitions must be published after the database commit.')
                return super().publish_device_presence(presence)

        connection = _Connection([
            _row(TENANT_ID, 'unstable'),
            _row(OTHER_TENANT_ID, 'offline', device_id='123e4567-e89b-12d3-a456-426614174002'),
        ], on_commit=lambda: _set_committed())

        def _set_committed() -> None:
            nonlocal committed
            committed = True

        hub = CommitCheckingHub()
        tenant_watcher = hub.subscribe(TENANT_ID)
        other_tenant_watcher = hub.subscribe(OTHER_TENANT_ID)
        monitor = PostgresDevicePresenceMonitor(_Pool(connection), hub)

        changed = await monitor.refresh()

        self.assertEqual(2, changed)
        self.assertTrue(committed)
        tenant_event = tenant_watcher.queue.get_nowait()
        other_tenant_event = other_tenant_watcher.queue.get_nowait()
        self.assertEqual('device.presence', tenant_event['type'])
        self.assertEqual('unstable', tenant_event['data']['connection_state'])
        self.assertEqual('offline', other_tenant_event['data']['connection_state'])
        self.assertIn("INTERVAL '30 seconds'", UPDATE_STALE_DEVICE_PRESENCE_SQL)
        self.assertIn("INTERVAL '90 seconds'", UPDATE_STALE_DEVICE_PRESENCE_SQL)

    async def test_offline_transition_clears_alerts_and_writes_outbox_before_commit(self) -> None:
        offline_device = '123e4567-e89b-12d3-a456-426614174002'
        alert_id = '123e4567-e89b-12d3-a456-426614174020'
        connection = _Connection(
            [_row(TENANT_ID, 'unstable'), _row(TENANT_ID, 'offline', device_id=offline_device)],
            on_commit=lambda: connection.events.append('commit'),
            cleared_alerts=[{
                'id': alert_id,
                'project_id': PROJECT_ID,
                'alert_rule_revision_id': '123e4567-e89b-12d3-a456-426614174021',
                'cleared_at': UPDATED_AT,
            }],
        )

        await PostgresDevicePresenceMonitor(_Pool(connection), PresenceHub()).refresh()

        self.assertEqual(
            [
                'fetch:stale',
                'execute:reset_states',
                'fetch:clear_alerts',
                'execute:alert_event',
                'fetchrow:snapshot',
                'execute:outbox',
                'commit',
            ],
            connection.events,
        )
        # Only the offline device is reset and cleared; the unstable one keeps its alerts.
        self.assertEqual([([offline_device],), ([offline_device],)], connection.args[1:3])
        outbox = connection.executed[INSERT_OUTBOX_EVENT_SQL]
        self.assertEqual(f'alert:{alert_id}:cleared', outbox[4])
        payload = json.loads(outbox[5])
        self.assertEqual('alert.cleared', payload['type'])
        self.assertEqual('device_offline', payload['alert']['clear_reason'])
        self.assertEqual(TENANT_ID, payload['tenant_id'])

    async def test_non_offline_transitions_do_not_touch_alerts(self) -> None:
        connection = _Connection([_row(TENANT_ID, 'unstable'), _row(TENANT_ID, 'online')], on_commit=lambda: None)

        await PostgresDevicePresenceMonitor(_Pool(connection), PresenceHub()).refresh()

        self.assertEqual(['fetch:stale'], connection.events)


def _row(tenant_id: str, state: str, *, device_id: str = DEVICE_ID) -> dict[str, object]:
    return {
        'tenant_id': tenant_id,
        'project_id': PROJECT_ID,
        'device_id': device_id,
        'connection_state': state,
        'last_seen_at': None,
        'updated_at': UPDATED_AT,
    }


class _Pool:
    def __init__(self, connection: _Connection) -> None:
        self.connection = connection

    def acquire(self) -> _AcquiredConnection:
        return _AcquiredConnection(self.connection)


class _AcquiredConnection:
    def __init__(self, connection: _Connection) -> None:
        self.connection = connection

    async def __aenter__(self) -> _Connection:
        return self.connection

    async def __aexit__(self, *_args: object) -> None:
        return None


class _Transaction:
    def __init__(self, connection: _Connection) -> None:
        self.connection = connection

    async def __aenter__(self) -> None:
        return None

    async def __aexit__(self, *_args: object) -> None:
        self.connection.on_commit()


class _Connection:
    def __init__(
        self,
        rows: list[dict[str, object]],
        on_commit: Callable[[], None],
        cleared_alerts: list[dict[str, object]] | None = None,
    ) -> None:
        self.rows = rows
        self.on_commit = on_commit
        self.cleared_alerts = cleared_alerts or []
        self.events: list[str] = []
        self.args: list[tuple[object, ...]] = []
        self.executed: dict[str, tuple[object, ...]] = {}

    def transaction(self) -> _Transaction:
        return _Transaction(self)

    async def fetch(self, sql: str, *args: object) -> list[dict[str, object]]:
        self.args.append(args)
        if sql == UPDATE_STALE_DEVICE_PRESENCE_SQL:
            self.events.append('fetch:stale')
            return self.rows
        if sql == CLEAR_OFFLINE_ALERTS_SQL:
            self.events.append('fetch:clear_alerts')
            return self.cleared_alerts
        raise AssertionError(f'Unexpected fetch SQL: {sql}')

    async def fetchrow(self, sql: str, *args: object) -> dict[str, object]:
        if sql != SELECT_ALERT_SNAPSHOT_SQL:
            raise AssertionError(f'Unexpected fetchrow SQL: {sql}')
        self.events.append('fetchrow:snapshot')
        return {
            'id': args[0], 'project_id': PROJECT_ID, 'tenant_id': TENANT_ID, 'alert_rule_id': 'rule-1',
            'rule_code': 'high_temperature', 'rule_revision': 1, 'severity': 'critical', 'device_id': DEVICE_ID,
            'equipment_asset_id': None, 'opened_at': UPDATED_AT, 'cleared_at': UPDATED_AT,
            'clear_reason': 'device_offline', 'acknowledged_at': None,
        }

    async def execute(self, sql: str, *args: object) -> str:
        names = {
            RESET_OFFLINE_EVALUATION_STATES_SQL: 'reset_states',
            INSERT_ALERT_EVENT_SQL: 'alert_event',
            INSERT_OUTBOX_EVENT_SQL: 'outbox',
        }
        if sql not in names:
            raise AssertionError(f'Unexpected execute SQL: {sql}')
        self.events.append(f'execute:{names[sql]}')
        self.args.append(args)
        self.executed[sql] = args
        return 'OK'
