from __future__ import annotations

import unittest
from collections.abc import Callable
from datetime import datetime, timedelta, timezone

from app.realtime.presence_hub import PresenceHub
from app.storage.presence import DevicePresence
from app.storage.presence_monitor import PostgresDevicePresenceMonitor, connection_state_for_age
from app.storage.queries import UPDATE_STALE_DEVICE_PRESENCE_SQL

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
    def __init__(self, rows: list[dict[str, object]], on_commit: Callable[[], None]) -> None:
        self.rows = rows
        self.on_commit = on_commit

    def transaction(self) -> _Transaction:
        return _Transaction(self)

    async def fetch(self, sql: str) -> list[dict[str, object]]:
        self.sql = sql
        return self.rows
