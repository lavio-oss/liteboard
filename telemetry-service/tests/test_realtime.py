from __future__ import annotations

import base64
import hashlib
import hmac
import importlib.util
import json
import time
import unittest
from datetime import datetime, timezone

from app.realtime.hub import RealtimeHub, sample_event
from app.realtime.ticket import verify_ticket
from app.storage.telemetry_ingest import PersistedSample

SECRET = b's' * 32
PROJECT_ID = '123e4567-e89b-12d3-a456-426614174000'
DEVICE_ID = '123e4567-e89b-12d3-a456-426614174001'
OTHER_DEVICE_ID = '123e4567-e89b-12d3-a456-426614174002'


def _b64(value: bytes) -> str:
    return base64.urlsafe_b64encode(value).rstrip(b'=').decode()


def make_ticket(*, device_id: str = DEVICE_ID, exp: float | None = None, secret: bytes = SECRET, sub: str = '7') -> str:
    payload = _b64(json.dumps({
        'sub': sub,
        'project_id': PROJECT_ID,
        'device_id': device_id,
        'exp': int(exp if exp is not None else time.time() + 60),
    }).encode())
    return payload + '.' + _b64(hmac.new(secret, payload.encode(), hashlib.sha256).digest())


class VerifyTicketTest(unittest.TestCase):
    def test_accepts_a_valid_ticket_signed_like_the_control_plane(self) -> None:
        claims = verify_ticket(make_ticket(), secret=SECRET, now=time.time())

        self.assertIsNotNone(claims)
        self.assertEqual((PROJECT_ID, DEVICE_ID, '7'), (claims.project_id, claims.device_id, claims.user_id))

    def test_rejects_expired_forged_and_malformed_tickets(self) -> None:
        payload, _, signature = make_ticket().partition('.')
        for ticket in (
            make_ticket(exp=time.time() - 1),
            make_ticket(secret=b'x' * 32),
            payload + '.' + signature[:-2] + 'AA',
            payload,
            '',
            '!!!.???',
        ):
            with self.subTest(ticket=ticket):
                self.assertIsNone(verify_ticket(ticket, secret=SECRET, now=time.time()))


class RealtimeHubTest(unittest.IsolatedAsyncioTestCase):
    async def test_sample_event_keeps_a_nullable_profile_version(self) -> None:
        occurred_at = datetime(2026, 10, 13, 10, 0, tzinfo=timezone.utc)
        event = sample_event(PersistedSample(
            id='sample-1',
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            device_profile_version_id=None,
            message_id='message-1',
            occurred_at=occurred_at,
            received_at=occurred_at,
            values={'temperature': 40},
        ))

        self.assertEqual('telemetry.sample', event['type'])
        self.assertIsNone(event['data']['device_profile_version_id'])

    async def test_publishes_only_to_the_matching_device(self) -> None:
        hub = RealtimeHub()
        watcher = hub.subscribe(PROJECT_ID, DEVICE_ID)
        other = hub.subscribe(PROJECT_ID, OTHER_DEVICE_ID)

        self.assertEqual(1, hub.publish(PROJECT_ID, DEVICE_ID, {'n': 1}))
        self.assertEqual({'n': 1}, watcher.queue.get_nowait())
        self.assertTrue(other.queue.empty())

    async def test_drops_a_slow_subscriber_instead_of_growing_its_queue(self) -> None:
        hub = RealtimeHub(queue_size=2)
        slow = hub.subscribe(PROJECT_ID, DEVICE_ID)

        for index in range(3):
            hub.publish(PROJECT_ID, DEVICE_ID, {'n': index})

        self.assertTrue(slow.overflowed.is_set())
        self.assertEqual(2, slow.queue.qsize())
        self.assertEqual(0, hub.connection_count)
        self.assertEqual(0, hub.publish(PROJECT_ID, DEVICE_ID, {'n': 4}))

    async def test_caps_total_connections(self) -> None:
        hub = RealtimeHub(max_connections=1)
        first = hub.subscribe(PROJECT_ID, DEVICE_ID)

        self.assertIsNone(hub.subscribe(PROJECT_ID, OTHER_DEVICE_ID))
        hub.unsubscribe(first)
        self.assertIsNotNone(hub.subscribe(PROJECT_ID, OTHER_DEVICE_ID))


@unittest.skipUnless(importlib.util.find_spec('httpx'), 'Starlette TestClient requires httpx.')
class TelemetryStreamTest(unittest.TestCase):
    def setUp(self) -> None:
        from fastapi import FastAPI
        from fastapi.testclient import TestClient

        from app.realtime.stream import telemetry_stream

        self.hub = RealtimeHub()
        application = FastAPI()
        application.state.ws_secret = SECRET
        application.state.realtime_hub = self.hub
        application.add_api_websocket_route('/ws/telemetry', telemetry_stream)
        self.client = TestClient(application)

    def _close_code(self, websocket: object) -> int:
        from starlette.websockets import WebSocketDisconnect

        with self.assertRaises(WebSocketDisconnect) as closed:
            websocket.receive_json()
        return closed.exception.code

    def test_streams_events_for_the_ticket_device_and_unsubscribes_on_close(self) -> None:
        with self.client.websocket_connect(f'/ws/telemetry?ticket={make_ticket()}') as websocket:
            _wait_for(lambda: self.hub.connection_count == 1)
            # The app runs on the TestClient portal loop; asyncio queues must be fed from that loop.
            websocket.portal.call(self.hub.publish, PROJECT_ID, OTHER_DEVICE_ID, {'type': 'telemetry.sample', 'data': {'id': 'other'}})
            websocket.portal.call(self.hub.publish, PROJECT_ID, DEVICE_ID, {'type': 'telemetry.sample', 'data': {'id': 'mine'}})

            self.assertEqual('mine', websocket.receive_json()['data']['id'])

        _wait_for(lambda: self.hub.connection_count == 0)

    def test_rejects_an_invalid_ticket(self) -> None:
        with self.client.websocket_connect('/ws/telemetry?ticket=forged') as websocket:
            self.assertEqual(4401, self._close_code(websocket))
        self.assertEqual(0, self.hub.connection_count)

    def test_renewal_for_another_device_closes_the_stream(self) -> None:
        with self.client.websocket_connect(f'/ws/telemetry?ticket={make_ticket()}') as websocket:
            websocket.send_json({'type': 'renew', 'ticket': make_ticket(device_id=OTHER_DEVICE_ID)})
            self.assertEqual(4403, self._close_code(websocket))

    def test_stream_closes_when_the_ticket_expires_without_renewal(self) -> None:
        with self.client.websocket_connect(f'/ws/telemetry?ticket={make_ticket(exp=time.time() + 1)}') as websocket:
            self.assertEqual(4403, self._close_code(websocket))

    def test_valid_renewal_keeps_the_stream_open(self) -> None:
        with self.client.websocket_connect(f'/ws/telemetry?ticket={make_ticket(exp=time.time() + 1)}') as websocket:
            websocket.send_json({'type': 'renew', 'ticket': make_ticket(exp=time.time() + 60)})
            time.sleep(1.5)
            websocket.portal.call(self.hub.publish, PROJECT_ID, DEVICE_ID, {'type': 'telemetry.sample', 'data': {'id': 'after-renew'}})

            self.assertEqual('after-renew', websocket.receive_json()['data']['id'])

    def test_slow_consumer_is_disconnected(self) -> None:
        with self.client.websocket_connect(f'/ws/telemetry?ticket={make_ticket()}') as websocket:
            _wait_for(lambda: self.hub.connection_count == 1)
            subscriber = next(iter(self.hub._subscribers[(PROJECT_ID, DEVICE_ID)]))
            websocket.portal.call(subscriber.overflowed.set)

            self.assertEqual(4008, self._close_code(websocket))


def _wait_for(condition: object, timeout: float = 2.0) -> None:
    deadline = time.monotonic() + timeout
    while not condition():
        if time.monotonic() > deadline:
            raise AssertionError('condition not met in time')
        time.sleep(0.01)

