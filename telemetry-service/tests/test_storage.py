from __future__ import annotations

import unittest
from unittest.mock import AsyncMock, MagicMock

from app.storage.presence import PostgresPresenceWriter
from app.storage.queries import UPDATE_DEVICE_PRESENCE_SQL


class PostgresPresenceWriterTest(unittest.IsolatedAsyncioTestCase):
    async def test_updates_only_active_approved_device_in_an_active_project_and_tenant(self) -> None:
        connection = AsyncMock()
        pool = _pool(connection)

        await PostgresPresenceWriter(pool).mark_online(device_id='device-id', project_id='project-id')

        pool.acquire.assert_called_once_with()
        connection.execute.assert_awaited_once_with(UPDATE_DEVICE_PRESENCE_SQL, 'device-id', 'project-id')
        self.assertIn('d.id = $1::uuid', UPDATE_DEVICE_PRESENCE_SQL)
        self.assertIn('d.project_id = $2::uuid', UPDATE_DEVICE_PRESENCE_SQL)
        self.assertIn("d.status = 'active'", UPDATE_DEVICE_PRESENCE_SQL)
        self.assertIn("d.approval_status = 'approved'", UPDATE_DEVICE_PRESENCE_SQL)
        self.assertIn("p.status = 'active'", UPDATE_DEVICE_PRESENCE_SQL)
        self.assertIn("t.status = 'active'", UPDATE_DEVICE_PRESENCE_SQL)

    async def test_database_error_propagates(self) -> None:
        connection = AsyncMock()
        connection.execute.side_effect = RuntimeError('database unavailable')

        with self.assertRaisesRegex(RuntimeError, 'database unavailable'):
            await PostgresPresenceWriter(_pool(connection)).mark_online(device_id='device-id', project_id='project-id')


def _pool(connection: AsyncMock) -> MagicMock:
    pool = MagicMock()
    pool.acquire.return_value = _AcquiredConnection(connection)
    return pool


class _AcquiredConnection:
    def __init__(self, connection: AsyncMock) -> None:
        self._connection = connection

    async def __aenter__(self) -> AsyncMock:
        return self._connection

    async def __aexit__(self, *_args: object) -> None:
        return None
