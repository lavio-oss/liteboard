from __future__ import annotations

import unittest
from unittest.mock import AsyncMock, MagicMock, patch

from app.pipeline.context import MessageContext
from app.pipeline.runner import run_pipeline
from app.realtime.presence_hub import PresenceHub
from app.pipeline.stages import MarkPresence, decode_json
from app.storage.presence import DevicePresence
from app.telemetry.validation import Rejection


def _ctx(raw: bytes, channel: str = 'heartbeat') -> MessageContext:
    return MessageContext(project_id='project-id', device_id='device-id', channel=channel, raw=raw)


def _writer() -> MagicMock:
    writer = MagicMock()
    writer.mark_online = AsyncMock()
    return writer


def _presence() -> DevicePresence:
    from datetime import datetime, timezone

    return DevicePresence(
        tenant_id='123e4567-e89b-12d3-a456-426614174010',
        project_id='123e4567-e89b-12d3-a456-426614174000',
        device_id='123e4567-e89b-12d3-a456-426614174001',
        connection_state='online',
        last_seen_at=datetime(2026, 10, 15, 10, 0, tzinfo=timezone.utc),
        updated_at=datetime(2026, 10, 15, 10, 0, tzinfo=timezone.utc),
    )


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
        presence = _presence()
        writer.mark_online.return_value = presence
        hub = MagicMock(spec=PresenceHub)

        await MarkPresence(writer, hub)(_ctx(b'{}'))

        writer.mark_online.assert_awaited_once_with(device_id='device-id', project_id='project-id')
        hub.publish_device_presence.assert_called_once_with(presence)


class RunPipelineTest(unittest.IsolatedAsyncioTestCase):
    async def test_runs_stages_in_order_and_logs_one_accepted_outcome(self) -> None:
        writer = _writer()
        hub = MagicMock(spec=PresenceHub)
        raw = b'{}'

        with patch('builtins.print') as print_mock:
            await run_pipeline([decode_json, MarkPresence(writer, hub)], _ctx(raw))

        writer.mark_online.assert_awaited_once()
        print_mock.assert_called_once_with(
            {
                'project_id': 'project-id',
                'device_id': 'device-id',
                'channel': 'heartbeat',
                'payload_size': len(raw),
                'outcome': 'accepted',
                'reasons': [],
            },
            flush=True,
        )

    async def test_stops_after_invalid_json_without_marking_presence(self) -> None:
        writer = _writer()
        hub = MagicMock(spec=PresenceHub)

        with patch('builtins.print') as print_mock:
            await run_pipeline([decode_json, MarkPresence(writer, hub)], _ctx(b'{invalid'))

        writer.mark_online.assert_not_awaited()
        hub.publish_device_presence.assert_not_called()
        self.assertEqual([{'code': 'invalid_json', 'field': None}], print_mock.call_args.args[0]['reasons'])

    async def test_infrastructure_errors_propagate_without_logging_an_outcome(self) -> None:
        writer = _writer()
        hub = MagicMock(spec=PresenceHub)
        writer.mark_online.side_effect = RuntimeError('database unavailable')

        with patch('builtins.print') as print_mock:
            with self.assertRaisesRegex(RuntimeError, 'database unavailable'):
                await run_pipeline([decode_json, MarkPresence(writer, hub)], _ctx(b'{}'))

        print_mock.assert_not_called()
