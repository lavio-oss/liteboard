from __future__ import annotations

import json
import unittest
from unittest.mock import AsyncMock, MagicMock

from app.storage.device_schema import SELECT_DEVICE_SCHEMA_SQL, PostgresDeviceSchemaReader
from app.storage.presence import UPDATE_DEVICE_PRESENCE_SQL, PostgresPresenceWriter
from app.telemetry.validation import DeviceSchema

DEFINITION = {
    'strict_mode': True,
    'fields': {'temperature': {'type': 'float', 'unit': 'celsius', 'required': True, 'max': 150}},
}


class PostgresPresenceWriterTest(unittest.IsolatedAsyncioTestCase):
    async def test_updates_only_active_device_in_the_given_project(self) -> None:
        connection = AsyncMock()
        connection.execute.return_value = 'UPDATE 0'
        pool = _pool(connection)

        await PostgresPresenceWriter(pool).mark_online(device_id='device-id', project_id='project-id')

        pool.acquire.assert_called_once_with()
        connection.execute.assert_awaited_once_with(UPDATE_DEVICE_PRESENCE_SQL, 'device-id', 'project-id')
        self.assertIn('WHERE id = $1::uuid', UPDATE_DEVICE_PRESENCE_SQL)
        self.assertIn('AND project_id = $2::uuid', UPDATE_DEVICE_PRESENCE_SQL)
        self.assertIn("AND status = 'active'", UPDATE_DEVICE_PRESENCE_SQL)

    async def test_database_error_propagates(self) -> None:
        connection = AsyncMock()
        connection.execute.side_effect = RuntimeError('database unavailable')

        with self.assertRaisesRegex(RuntimeError, 'database unavailable'):
            await PostgresPresenceWriter(_pool(connection)).mark_online(device_id='device-id', project_id='project-id')


class PostgresDeviceSchemaReaderTest(unittest.IsolatedAsyncioTestCase):
    async def test_reads_the_assigned_version_only_for_an_active_device_in_the_given_project(self) -> None:
        connection = AsyncMock()
        connection.fetchrow.return_value = {'version': 3, 'definition': json.dumps(DEFINITION)}

        schema = await PostgresDeviceSchemaReader(_pool(connection)).find_for_device(
            device_id='device-id',
            project_id='project-id',
        )

        self.assertEqual(DeviceSchema(version=3, definition=DEFINITION), schema)
        connection.fetchrow.assert_awaited_once_with(SELECT_DEVICE_SCHEMA_SQL, 'device-id', 'project-id')
        self.assertIn('d.id = $1::uuid', SELECT_DEVICE_SCHEMA_SQL)
        self.assertIn('d.project_id = $2::uuid', SELECT_DEVICE_SCHEMA_SQL)
        self.assertIn("d.status = 'active'", SELECT_DEVICE_SCHEMA_SQL)
        self.assertIn('dpv.id = d.device_profile_version_id', SELECT_DEVICE_SCHEMA_SQL)
        self.assertIn('dpv.project_id = d.project_id', SELECT_DEVICE_SCHEMA_SQL)

    async def test_accepts_a_definition_already_decoded_by_the_driver(self) -> None:
        connection = AsyncMock()
        connection.fetchrow.return_value = {'version': 1, 'definition': DEFINITION}

        schema = await PostgresDeviceSchemaReader(_pool(connection)).find_for_device(
            device_id='device-id',
            project_id='project-id',
        )

        self.assertEqual(DeviceSchema(version=1, definition=DEFINITION), schema)

    async def test_returns_none_when_no_schema_is_assigned(self) -> None:
        connection = AsyncMock()
        connection.fetchrow.return_value = None

        schema = await PostgresDeviceSchemaReader(_pool(connection)).find_for_device(
            device_id='device-id',
            project_id='project-id',
        )

        self.assertIsNone(schema)


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
