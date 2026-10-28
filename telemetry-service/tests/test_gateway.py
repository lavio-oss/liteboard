from __future__ import annotations

import json
import unittest
from datetime import datetime, timezone
from unittest.mock import AsyncMock

from app.ingress.gateway import GatewayHandler, parse_gateway_payload
from app.ingress.sub_device_registry import SubDeviceRejected
from app.realtime.presence_hub import PresenceHub
from app.storage.queries import MARK_GATEWAY_PRESENCE_SQL, SELECT_DEVICE_BY_CODE_SQL, UPDATE_DEVICE_PRESENCE_SQL
from app.storage.telemetry_ingest import IngestResult

PROJECT_ID = '123e4567-e89b-12d3-a456-426614174000'
GATEWAY_ID = '123e4567-e89b-12d3-a456-426614174001'
OTHER_GATEWAY_ID = '123e4567-e89b-12d3-a456-426614174002'
SUB_DEVICE_ID = '123e4567-e89b-12d3-a456-426614174003'
TENANT_ID = '123e4567-e89b-12d3-a456-426614174010'
TOPIC = f'liteboard/{PROJECT_ID}/devices/{GATEWAY_ID}/gateway/telemetry'
NOW = datetime(2026, 10, 28, 10, 0, tzinfo=timezone.utc)


def _presence_row(device_id: str) -> dict[str, object]:
    return {
        'tenant_id': TENANT_ID,
        'project_id': PROJECT_ID,
        'device_id': device_id,
        'connection_state': 'online',
        'last_seen_at': NOW,
        'updated_at': NOW,
    }


class _FakePool:
    def __init__(self, *, gateway_active: bool = True, devices: dict[str, dict[str, object]] | None = None) -> None:
        self.gateway_active = gateway_active
        self.devices = devices or {}
        self.unresolved: list[str] = []
        self.presence_updates: list[str] = []

    async def fetchrow(self, sql: str, *args: object) -> dict[str, object] | None:
        if sql == MARK_GATEWAY_PRESENCE_SQL:
            return _presence_row(GATEWAY_ID) if self.gateway_active else None
        if sql == SELECT_DEVICE_BY_CODE_SQL:
            return self.devices.get(str(args[1]))
        if sql == UPDATE_DEVICE_PRESENCE_SQL:
            self.presence_updates.append(str(args[0]))
            return _presence_row(str(args[0]))
        raise AssertionError(f'unexpected query {sql}')

    async def execute(self, _sql: str, *args: object) -> None:
        self.unresolved.append(str(args[2]))


def _handler(pool: _FakePool, registry: AsyncMock | None = None) -> tuple[GatewayHandler, AsyncMock, AsyncMock]:
    ingestor = AsyncMock()
    ingestor.ingest.return_value = IngestResult('persisted', 'p-1')
    registry = registry or AsyncMock()
    registry.register.return_value = SUB_DEVICE_ID
    handler = GatewayHandler(db_pool=pool, telemetry_ingestor=ingestor, registry=registry, presence_hub=PresenceHub())
    return handler, ingestor, registry


async def _handle(handler: GatewayHandler, payload: object, action: str = 'telemetry') -> list[IngestResult]:
    return await handler.handle(
        project_id=PROJECT_ID,
        gateway_id=GATEWAY_ID,
        action=action,
        topic=TOPIC,
        raw=json.dumps(payload).encode(),
        retained=False,
        received_wall=NOW,
    )


class ParseGatewayPayloadTest(unittest.TestCase):
    def test_accepts_telemetry_batches_and_connect_messages(self) -> None:
        self.assertEqual({'a': [{'t': 1}]}, parse_gateway_payload('telemetry', b'{"a":[{"t":1}]}'))
        self.assertEqual({'a': []}, parse_gateway_payload('connect', b'{"device":"a"}'))

    def test_rejects_malformed_payloads(self) -> None:
        for action, raw in (
            ('telemetry', b'not json'),
            ('telemetry', b'[]'),
            ('telemetry', b'{}'),
            ('telemetry', b'{"a":[]}'),
            ('telemetry', b'{"a":{"t":1}}'),
            ('telemetry', b'{"a":[1]}'),
            ('telemetry', json.dumps({'x' * 65: [{'t': 1}]}).encode()),
            ('connect', b'{"device":""}'),
            ('disconnect', b'{}'),
        ):
            with self.subTest(action=action, raw=raw):
                self.assertIsNone(parse_gateway_payload(action, raw))


class GatewayHandlerTest(unittest.IsolatedAsyncioTestCase):
    async def test_ingests_each_item_for_a_known_sub_device(self) -> None:
        pool = _FakePool(devices={'a': {'id': SUB_DEVICE_ID, 'gateway_device_id': GATEWAY_ID}})
        handler, ingestor, registry = _handler(pool)

        results = await _handle(handler, {'a': [{'t': 1}, {'t': 2}]})

        self.assertEqual(2, len(results))
        registry.register.assert_not_awaited()
        self.assertEqual(
            [json.dumps({'t': 1}).encode(), json.dumps({'t': 2}).encode()],
            [call.kwargs['raw'] for call in ingestor.ingest.await_args_list],
        )
        self.assertTrue(all(call.kwargs['device_id'] == SUB_DEVICE_ID for call in ingestor.ingest.await_args_list))

    async def test_registers_an_unknown_code_through_the_registry(self) -> None:
        pool = _FakePool()
        handler, ingestor, registry = _handler(pool)

        await _handle(handler, {'new-sensor': [{'t': 1}]})

        registry.register.assert_awaited_once_with(gateway_id=GATEWAY_ID, code='new-sensor')
        self.assertEqual(SUB_DEVICE_ID, ingestor.ingest.await_args.kwargs['device_id'])

    async def test_never_writes_into_a_device_owned_by_someone_else(self) -> None:
        pool = _FakePool(devices={
            'direct': {'id': SUB_DEVICE_ID, 'gateway_device_id': None},
            'theirs': {'id': SUB_DEVICE_ID, 'gateway_device_id': OTHER_GATEWAY_ID},
        })
        handler, ingestor, _ = _handler(pool)

        results = await _handle(handler, {'direct': [{'t': 1}], 'theirs': [{'t': 1}]})

        self.assertEqual([], results)
        ingestor.ingest.assert_not_awaited()
        self.assertEqual(['gateway_mismatch', 'gateway_mismatch'], pool.unresolved)

    async def test_records_registry_rejections_and_lets_transient_failures_raise(self) -> None:
        pool = _FakePool()
        handler, ingestor, registry = _handler(pool)
        registry.register.side_effect = SubDeviceRejected('PROJECT_DEVICE_QUOTA_EXCEEDED')

        await _handle(handler, {'a': [{'t': 1}]})

        self.assertEqual(['sub_device_rejected'], pool.unresolved)
        ingestor.ingest.assert_not_awaited()

        registry.register.side_effect = OSError('laravel unreachable')
        with self.assertRaises(OSError):
            await _handle(handler, {'a': [{'t': 1}]})

    async def test_rejects_non_gateways_and_bad_payloads_without_ingesting(self) -> None:
        handler, ingestor, _ = _handler(pool := _FakePool(gateway_active=False))
        await _handle(handler, {'a': [{'t': 1}]})
        self.assertEqual(['gateway_not_found'], pool.unresolved)

        handler, ingestor, _ = _handler(pool := _FakePool())
        await _handle(handler, {'a': 'nope'})
        self.assertEqual(['gateway_payload_invalid'], pool.unresolved)
        ingestor.ingest.assert_not_awaited()

    async def test_connect_marks_the_sub_device_online(self) -> None:
        pool = _FakePool(devices={'a': {'id': SUB_DEVICE_ID, 'gateway_device_id': GATEWAY_ID}})
        handler, ingestor, _ = _handler(pool)

        results = await _handle(handler, {'device': 'a'}, action='connect')

        self.assertEqual([], results)
        self.assertEqual([SUB_DEVICE_ID], pool.presence_updates)
        ingestor.ingest.assert_not_awaited()
