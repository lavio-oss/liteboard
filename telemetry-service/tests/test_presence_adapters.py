from __future__ import annotations

import unittest
from unittest.mock import AsyncMock, patch

from app.adapters.postgres_presence_writer import UPDATE_DEVICE_PRESENCE_SQL, PostgresPresenceWriter
from app.application.update_device_presence import UpdateDevicePresence


class PostgresPresenceWriterTest(unittest.IsolatedAsyncioTestCase):
    async def test_updates_only_active_device_in_the_given_project(self) -> None:
        connection = unittest.mock.AsyncMock()
        connection.execute.return_value = 'UPDATE 0'
        acquired_connection = _AcquiredConnection(connection)
        db_pool = unittest.mock.MagicMock()
        db_pool.acquire.return_value = acquired_connection
        writer = PostgresPresenceWriter(db_pool)

        await writer.mark_online(device_id='device-id', project_id='project-id')

        db_pool.acquire.assert_called_once_with()
        connection.execute.assert_awaited_once_with(UPDATE_DEVICE_PRESENCE_SQL, 'device-id', 'project-id')
        self.assertIn('WHERE id = $1::uuid', UPDATE_DEVICE_PRESENCE_SQL)
        self.assertIn('AND project_id = $2::uuid', UPDATE_DEVICE_PRESENCE_SQL)
        self.assertIn("AND status = 'active'", UPDATE_DEVICE_PRESENCE_SQL)

    async def test_database_error_propagates(self) -> None:
        connection = unittest.mock.AsyncMock()
        connection.execute.side_effect = RuntimeError('database unavailable')
        db_pool = unittest.mock.MagicMock()
        db_pool.acquire.return_value = _AcquiredConnection(connection)
        writer = PostgresPresenceWriter(db_pool)

        with self.assertRaisesRegex(RuntimeError, 'database unavailable'):
            await writer.mark_online(device_id='device-id', project_id='project-id')


class UpdateDevicePresenceTest(unittest.IsolatedAsyncioTestCase):
    async def test_logs_after_writer_completes_even_when_update_matches_zero_rows(self) -> None:
        events: list[str] = []

        class PresenceWriterStub:
            async def mark_online(self, *, device_id: str, project_id: str) -> None:
                events.append('database')

        handler = UpdateDevicePresence(PresenceWriterStub())

        def capture_print(*_args: object, **_kwargs: object) -> None:
            events.append('log')

        with patch('builtins.print', side_effect=capture_print) as print_mock:
            await handler.execute(
                project_id='project-id',
                device_id='device-id',
                channel='telemetry',
                payload_size=17,
            )

        self.assertEqual(['database', 'log'], events)
        print_mock.assert_called_once_with(
            {
                'project_id': 'project-id',
                'device_id': 'device-id',
                'channel': 'telemetry',
                'payload_size': 17,
            },
            flush=True,
        )

    async def test_does_not_log_when_writer_fails(self) -> None:
        class FailingPresenceWriter:
            async def mark_online(self, *, device_id: str, project_id: str) -> None:
                raise RuntimeError('database unavailable')

        handler = UpdateDevicePresence(FailingPresenceWriter())

        with patch('builtins.print') as print_mock:
            with self.assertRaisesRegex(RuntimeError, 'database unavailable'):
                await handler.execute(
                    project_id='project-id',
                    device_id='device-id',
                    channel='heartbeat',
                    payload_size=2,
                )

        print_mock.assert_not_called()


class _AcquiredConnection:
    def __init__(self, connection: AsyncMock) -> None:
        self._connection = connection

    async def __aenter__(self) -> AsyncMock:
        return self._connection

    async def __aexit__(self, *_args: object) -> None:
        return None
