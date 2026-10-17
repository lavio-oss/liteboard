from __future__ import annotations

import base64
import hashlib
import hmac
import importlib.util
import json
import time
import unittest
from collections.abc import Callable

from app.realtime.presence_hub import PresenceHub
from app.realtime.presence_ticket import verify_presence_ticket

SECRET = b's' * 32
USER_ID = '123e4567-e89b-12d3-a456-426614174020'
TENANT_ID = '123e4567-e89b-12d3-a456-426614174010'
OTHER_TENANT_ID = '123e4567-e89b-12d3-a456-426614174011'
PROJECT_ID = '123e4567-e89b-12d3-a456-426614174000'
DEVICE_ID = '123e4567-e89b-12d3-a456-426614174001'


def _b64(value: bytes) -> str:
    return base64.urlsafe_b64encode(value).rstrip(b'=').decode()


def make_presence_ticket(
    *,
    tenant_id: str = TENANT_ID,
    exp: float | None = None,
    secret: bytes = SECRET,
    sub: str = USER_ID,
    scope: str = 'device-presence',
) -> str:
    payload = _b64(json.dumps({
        'sub': sub,
        'tenant_id': tenant_id,
        'scope': scope,
        'exp': int(exp if exp is not None else time.time() + 60),
    }).encode())
    return payload + '.' + _b64(hmac.new(secret, payload.encode(), hashlib.sha256).digest())


class VerifyPresenceTicketTest(unittest.TestCase):
    def test_accepts_a_valid_tenant_presence_ticket(self) -> None:
        claims = verify_presence_ticket(make_presence_ticket(), secret=SECRET, now=time.time())

        self.assertIsNotNone(claims)
        self.assertEqual((USER_ID, TENANT_ID), (claims.user_id, claims.tenant_id))

    def test_rejects_expired_forged_and_wrong_scope_tickets(self) -> None:
        for ticket in (
            make_presence_ticket(exp=time.time() - 1),
            make_presence_ticket(secret=b'x' * 32),
            make_presence_ticket(scope='telemetry-device'),
            make_presence_ticket(tenant_id='not-a-uuid'),
            'forged',
        ):
            with self.subTest(ticket=ticket):
                self.assertIsNone(verify_presence_ticket(ticket, secret=SECRET, now=time.time()))


class PresenceHubTest(unittest.IsolatedAsyncioTestCase):
    async def test_routes_events_only_to_subscribers_of_the_resolved_tenant(self) -> None:
        hub = PresenceHub()
        tenant_watcher = hub.subscribe(TENANT_ID)
        other_watcher = hub.subscribe(OTHER_TENANT_ID)
        event = {
            'type': 'device.presence',
            'data': {'project_id': PROJECT_ID, 'device_id': DEVICE_ID, 'connection_state': 'online'},
        }

        self.assertEqual(1, hub.publish(TENANT_ID, event))
        self.assertEqual(event, tenant_watcher.queue.get_nowait())
        self.assertTrue(other_watcher.queue.empty())


@unittest.skipUnless(importlib.util.find_spec('httpx'), 'Starlette TestClient requires httpx.')
class PresenceStreamTest(unittest.TestCase):
    def setUp(self) -> None:
        from fastapi import FastAPI
        from fastapi.testclient import TestClient

        from app.realtime.presence_stream import device_presence_stream

        self.hub = PresenceHub()
        application = FastAPI()
        application.state.ws_secret = SECRET
        application.state.presence_hub = self.hub
        application.add_api_websocket_route('/ws/device-presence', device_presence_stream)
        self.client = TestClient(application)

    def _close_code(self, websocket: object) -> int:
        from starlette.websockets import WebSocketDisconnect

        with self.assertRaises(WebSocketDisconnect) as closed:
            websocket.receive_json()
        return closed.exception.code

    def test_streams_only_events_routed_to_the_ticket_tenant(self) -> None:
        event = {
            'type': 'device.presence',
            'data': {'project_id': PROJECT_ID, 'device_id': DEVICE_ID, 'connection_state': 'unstable'},
        }
        with self.client.websocket_connect(f'/ws/device-presence?ticket={make_presence_ticket()}') as websocket:
            _wait_for(lambda: self.hub.connection_count == 1)
            websocket.portal.call(self.hub.publish, OTHER_TENANT_ID, {'type': 'device.presence', 'data': {'device_id': 'other'}})
            websocket.portal.call(self.hub.publish, TENANT_ID, event)
            self.assertEqual(event, websocket.receive_json())

        _wait_for(lambda: self.hub.connection_count == 0)

    def test_rejects_tenant_or_user_changes_during_renewal(self) -> None:
        for ticket in (
            make_presence_ticket(tenant_id=OTHER_TENANT_ID),
            make_presence_ticket(sub='123e4567-e89b-12d3-a456-426614174021'),
        ):
            with self.subTest(ticket=ticket):
                with self.client.websocket_connect(f'/ws/device-presence?ticket={make_presence_ticket()}') as websocket:
                    websocket.send_json({'type': 'renew', 'ticket': ticket})
                    self.assertEqual(4403, self._close_code(websocket))

    def test_keeps_the_stream_open_after_a_valid_renewal(self) -> None:
        event = {'type': 'device.presence', 'data': {'device_id': DEVICE_ID}}
        with self.client.websocket_connect(
            f'/ws/device-presence?ticket={make_presence_ticket(exp=time.time() + 1)}',
        ) as websocket:
            websocket.send_json({'type': 'renew', 'ticket': make_presence_ticket(exp=time.time() + 60)})
            time.sleep(1.5)
            websocket.portal.call(self.hub.publish, TENANT_ID, event)
            self.assertEqual(event, websocket.receive_json())


def _wait_for(condition: Callable[[], bool], timeout: float = 2.0) -> None:
    deadline = time.monotonic() + timeout
    while not condition():
        if time.monotonic() > deadline:
            raise AssertionError('condition not met in time')
        time.sleep(0.01)
