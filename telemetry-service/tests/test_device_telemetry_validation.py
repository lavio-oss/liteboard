from __future__ import annotations

import json
import unittest
from unittest.mock import AsyncMock, MagicMock, patch

from app.adapters.postgres_device_schema_reader import (
    SELECT_DEVICE_SCHEMA_SQL,
    PostgresDeviceSchemaReader,
)
from app.application.device_schema import DeviceSchema
from app.application.validate_device_telemetry import ValidateDeviceTelemetry
from app.application.validate_telemetry import Rejection

DEFINITION = {
    'strict_mode': True,
    'fields': {'temperature': {'type': 'float', 'unit': 'celsius', 'required': True, 'max': 150}},
}


class PostgresDeviceSchemaReaderTest(unittest.IsolatedAsyncioTestCase):
    async def test_reads_the_assigned_version_only_for_an_active_device_in_the_given_project(self) -> None:
        connection = AsyncMock()
        connection.fetchrow.return_value = {'version': 3, 'definition': json.dumps(DEFINITION)}
        reader = PostgresDeviceSchemaReader(_pool(connection))

        schema = await reader.find_for_device(device_id='device-id', project_id='project-id')

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


class ValidateDeviceTelemetryTest(unittest.IsolatedAsyncioTestCase):
    async def test_logs_accepted_payload(self) -> None:
        handler = ValidateDeviceTelemetry(_reader(DeviceSchema(version=1, definition=DEFINITION)))

        with patch('builtins.print') as print_mock:
            result = await handler.execute(
                project_id='project-id',
                device_id='device-id',
                payload={'values': {'temperature': 40}},
            )

        self.assertTrue(result.accepted)
        print_mock.assert_called_once_with(
            {'project_id': 'project-id', 'device_id': 'device-id', 'outcome': 'accepted', 'reasons': []},
            flush=True,
        )

    async def test_logs_rejection_reasons(self) -> None:
        handler = ValidateDeviceTelemetry(_reader(DeviceSchema(version=1, definition=DEFINITION)))

        with patch('builtins.print') as print_mock:
            result = await handler.execute(
                project_id='project-id',
                device_id='device-id',
                payload={'values': {'temperature': 200}},
            )

        self.assertFalse(result.accepted)
        print_mock.assert_called_once_with(
            {
                'project_id': 'project-id',
                'device_id': 'device-id',
                'outcome': 'rejected',
                'reasons': [{'code': 'above_maximum', 'field': 'temperature'}],
            },
            flush=True,
        )

    async def test_rejects_telemetry_from_a_device_without_an_assigned_schema(self) -> None:
        reader = _reader(None)
        handler = ValidateDeviceTelemetry(reader)

        with patch('builtins.print') as print_mock:
            result = await handler.execute(
                project_id='project-id',
                device_id='device-id',
                payload={'values': {'temperature': 40}},
            )

        reader.find_for_device.assert_awaited_once_with(device_id='device-id', project_id='project-id')
        self.assertEqual((Rejection('schema_not_assigned'),), result.reasons)
        print_mock.assert_called_once_with(
            {
                'project_id': 'project-id',
                'device_id': 'device-id',
                'outcome': 'rejected',
                'reasons': [{'code': 'schema_not_assigned', 'field': None}],
            },
            flush=True,
        )


def _reader(schema: DeviceSchema | None) -> MagicMock:
    reader = MagicMock()
    reader.find_for_device = AsyncMock(return_value=schema)
    return reader


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
