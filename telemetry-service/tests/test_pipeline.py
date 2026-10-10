from __future__ import annotations

import unittest
from unittest.mock import AsyncMock, MagicMock, patch

from app.pipeline.context import MessageContext
from app.pipeline.runner import run_pipeline
from app.pipeline.stages import MarkPresence, ValidateSchema, decode_json
from app.telemetry.validation import DeviceSchema, Rejection

DEFINITION = {
    'strict_mode': True,
    'fields': {'temperature': {'type': 'float', 'unit': 'celsius', 'required': True, 'max': 150}},
}


def _ctx(raw: bytes, channel: str = 'telemetry') -> MessageContext:
    return MessageContext(project_id='project-id', device_id='device-id', channel=channel, raw=raw)


def _writer() -> MagicMock:
    writer = MagicMock()
    writer.mark_online = AsyncMock()
    return writer


def _reader(schema: DeviceSchema | None) -> MagicMock:
    reader = MagicMock()
    reader.find_for_device = AsyncMock(return_value=schema)
    return reader


class DecodeJsonTest(unittest.IsolatedAsyncioTestCase):
    async def test_decodes_json_objects_including_empty_object(self) -> None:
        for raw, expected in ((b'{"temperature": 22.5}', {'temperature': 22.5}), (b'{}', {})):
            with self.subTest(raw=raw):
                ctx = _ctx(raw)
                await decode_json(ctx)

                self.assertEqual(expected, ctx.payload)
                self.assertEqual([], ctx.reasons)

    async def test_rejects_empty_invalid_utf8_invalid_json_and_non_objects(self) -> None:
        for raw in (b'', b'\xff', b'{invalid', b'[]', b'null', b'42'):
            with self.subTest(raw=raw):
                ctx = _ctx(raw)
                await decode_json(ctx)

                self.assertIsNone(ctx.payload)
                self.assertEqual([Rejection('invalid_json')], ctx.reasons)


class StagesTest(unittest.IsolatedAsyncioTestCase):
    async def test_mark_presence_uses_device_and_project_from_the_topic(self) -> None:
        writer = _writer()

        await MarkPresence(writer)(_ctx(b'{}'))

        writer.mark_online.assert_awaited_once_with(device_id='device-id', project_id='project-id')

    async def test_validate_schema_reads_schema_by_device_and_project(self) -> None:
        reader = _reader(DeviceSchema(version=1, definition=DEFINITION))
        ctx = _ctx(b'')
        ctx.payload = {'values': {'temperature': 200}}

        await ValidateSchema(reader)(ctx)

        reader.find_for_device.assert_awaited_once_with(device_id='device-id', project_id='project-id')
        self.assertEqual([Rejection('above_maximum', 'temperature')], ctx.reasons)

    async def test_validate_schema_rejects_device_without_assigned_schema(self) -> None:
        ctx = _ctx(b'')
        ctx.payload = {'values': {'temperature': 40}}

        await ValidateSchema(_reader(None))(ctx)

        self.assertEqual([Rejection('schema_not_assigned')], ctx.reasons)


class RunPipelineTest(unittest.IsolatedAsyncioTestCase):
    async def test_runs_stages_in_order_and_logs_one_accepted_outcome(self) -> None:
        writer = _writer()
        stages = [decode_json, MarkPresence(writer), ValidateSchema(_reader(DeviceSchema(1, DEFINITION)))]
        raw = b'{"values": {"temperature": 40}}'

        with patch('builtins.print') as print_mock:
            await run_pipeline(stages, _ctx(raw))

        writer.mark_online.assert_awaited_once()
        print_mock.assert_called_once_with(
            {
                'project_id': 'project-id',
                'device_id': 'device-id',
                'channel': 'telemetry',
                'payload_size': len(raw),
                'outcome': 'accepted',
                'reasons': [],
            },
            flush=True,
        )

    async def test_keeps_presence_when_schema_validation_rejects(self) -> None:
        writer = _writer()
        stages = [decode_json, MarkPresence(writer), ValidateSchema(_reader(DeviceSchema(1, DEFINITION)))]

        with patch('builtins.print') as print_mock:
            await run_pipeline(stages, _ctx(b'{"values": {"temperature": 200}}'))

        writer.mark_online.assert_awaited_once()
        logged = print_mock.call_args.args[0]
        self.assertEqual('rejected', logged['outcome'])
        self.assertEqual([{'code': 'above_maximum', 'field': 'temperature'}], logged['reasons'])

    async def test_stops_at_the_first_rejecting_stage(self) -> None:
        writer = _writer()
        reader = _reader(DeviceSchema(1, DEFINITION))

        with patch('builtins.print') as print_mock:
            await run_pipeline([decode_json, MarkPresence(writer), ValidateSchema(reader)], _ctx(b'{invalid'))

        writer.mark_online.assert_not_awaited()
        reader.find_for_device.assert_not_awaited()
        self.assertEqual([{'code': 'invalid_json', 'field': None}], print_mock.call_args.args[0]['reasons'])

    async def test_heartbeat_pipeline_does_not_validate(self) -> None:
        writer = _writer()

        with patch('builtins.print') as print_mock:
            await run_pipeline([decode_json, MarkPresence(writer)], _ctx(b'{}', channel='heartbeat'))

        writer.mark_online.assert_awaited_once()
        self.assertEqual('heartbeat', print_mock.call_args.args[0]['channel'])
        self.assertEqual('accepted', print_mock.call_args.args[0]['outcome'])

    async def test_infrastructure_errors_propagate_without_logging_an_outcome(self) -> None:
        writer = _writer()
        writer.mark_online.side_effect = RuntimeError('database unavailable')

        with patch('builtins.print') as print_mock:
            with self.assertRaisesRegex(RuntimeError, 'database unavailable'):
                await run_pipeline([decode_json, MarkPresence(writer)], _ctx(b'{}'))

        print_mock.assert_not_called()
