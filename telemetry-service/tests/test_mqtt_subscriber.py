from __future__ import annotations

import asyncio
import os
import unittest
from unittest.mock import AsyncMock, patch

from app.ingress.mqtt_subscriber import (
    HEARTBEAT_TOPIC,
    SUCCESSFUL_PUBACK,
    TELEMETRY_TOPIC,
    MqttSubscriber,
    parse_device_topic,
)
from app.pipeline.context import MessageContext
from app.storage.telemetry_ingest import IngestResult

PROJECT_ID = '123e4567-e89b-12d3-a456-426614174000'
DEVICE_ID = '123e4567-e89b-12d3-a456-426614174001'
TELEMETRY_PATH = f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/telemetry'
HEARTBEAT_PATH = f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/heartbeat'


class ParseDeviceTopicTest(unittest.TestCase):
    def test_parses_telemetry_and_heartbeat_topics_without_rewriting_ids(self) -> None:
        self.assertEqual((PROJECT_ID, DEVICE_ID, 'telemetry'), parse_device_topic(TELEMETRY_PATH))
        self.assertEqual((PROJECT_ID, DEVICE_ID, 'heartbeat'), parse_device_topic(HEARTBEAT_PATH))

    def test_rejects_wrong_shape_prefix_resource_channel_and_invalid_uuids(self) -> None:
        invalid_topics = (
            'liteboard/project/devices/device/telemetry/extra',
            f'other/{PROJECT_ID}/devices/{DEVICE_ID}/telemetry',
            f'liteboard/{PROJECT_ID}/assets/{DEVICE_ID}/telemetry',
            f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/commands',
            f'liteboard/not-a-uuid/devices/{DEVICE_ID}/telemetry',
            f'liteboard/{PROJECT_ID}/devices/not-a-uuid/telemetry',
        )

        for topic in invalid_topics:
            with self.subTest(topic=topic):
                self.assertIsNone(parse_device_topic(topic))


class MqttSubscriberTest(unittest.IsolatedAsyncioTestCase):
    async def test_connects_with_mqtt_v5_persistent_session_and_bounded_receive_window(self) -> None:
        tls_context = object()
        with (
            patch.dict(
                os.environ,
                {
                    'MQTT_USERNAME': 'telemetry-service',
                    'MQTT_PASSWORD': 'internal-password',
                    'MQTT_TLS_CA_FILE': '/run/secrets/ca.crt',
                    'MQTT_SESSION_EXPIRY_SECONDS': '3600',
                    'MQTT_RECEIVE_MAXIMUM': '12',
                },
                clear=True,
            ),
            patch('app.ingress.mqtt_subscriber.MQTTClient', _FakeMqttClient),
            patch('app.ingress.mqtt_subscriber.build_mqtt_ssl_context', return_value=tls_context) as build_ssl,
        ):
            subscriber = _subscriber()
            await subscriber.connect_and_subscribe()

        self.assertEqual('liteboard-telemetry-service', subscriber.client.client_id)
        self.assertEqual(
            {
                'clean_session': False,
                'optimistic_acknowledgement': False,
                'session_expiry_interval': 3600,
                'receive_maximum': 12,
            },
            subscriber.client.options,
        )
        self.assertEqual(('telemetry-service', 'internal-password'), subscriber.client.credentials)
        build_ssl.assert_called_once_with('/run/secrets/ca.crt')
        self.assertEqual(('localhost', 8883, tls_context, 5), subscriber.client.connection)
        self.assertEqual(
            [(TELEMETRY_TOPIC, 1, True), (HEARTBEAT_TOPIC, 1, True)],
            subscriber.client.subscriptions,
        )
        self.assertTrue(subscriber.is_connected)

    async def test_subscribes_again_only_when_the_broker_has_no_persistent_session(self) -> None:
        subscriber = _subscriber()
        subscriber.on_connect(subscriber.client, 1, 0, {})
        self.assertEqual([], subscriber.client.subscriptions)

        subscriber.on_connect(subscriber.client, 0, 0, {})

        self.assertEqual(
            [(TELEMETRY_TOPIC, 1, True), (HEARTBEAT_TOPIC, 1, True)],
            subscriber.client.subscriptions,
        )

    async def test_returns_puback_success_after_the_telemetry_transaction_finishes(self) -> None:
        started = asyncio.Event()
        commit = asyncio.Event()
        ingestor = _FakeIngestor()
        committed = False

        async def ingest(**_kwargs: object) -> IngestResult:
            nonlocal committed
            started.set()
            await commit.wait()
            committed = True
            return IngestResult('persisted')

        ingestor.ingest.side_effect = ingest
        subscriber = _subscriber(ingestor=ingestor)

        acknowledgement = asyncio.create_task(
            subscriber.on_message(subscriber.client, TELEMETRY_PATH, b'{}', 1, {'retain': False}),
        )
        await started.wait()

        self.assertFalse(acknowledgement.done())
        self.assertFalse(committed)
        commit.set()

        self.assertEqual(SUCCESSFUL_PUBACK, await acknowledgement)
        self.assertTrue(committed)

    async def test_rejects_retained_telemetry_but_heartbeat_keeps_its_presence_pipeline(self) -> None:
        ingestor = _FakeIngestor()
        subscriber = _subscriber(ingestor=ingestor, heartbeat_pipeline=['heartbeat-stages'])

        ack = await subscriber.on_message(subscriber.client, TELEMETRY_PATH, b'{}', 1, {'retain': 1})

        self.assertEqual(SUCCESSFUL_PUBACK, ack)
        ingestor.ingest.assert_awaited_once_with(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            raw=b'{}',
            retained=True,
        )
        with patch('app.ingress.mqtt_subscriber.run_pipeline', new=AsyncMock()) as run_pipeline:
            ack = await subscriber.on_message(subscriber.client, HEARTBEAT_PATH, b'{}', 1, {'retain': True})

        self.assertEqual(SUCCESSFUL_PUBACK, ack)
        run_pipeline.assert_awaited_once_with(
            ['heartbeat-stages'],
            MessageContext(project_id=PROJECT_ID, device_id=DEVICE_ID, channel='heartbeat', raw=b'{}'),
        )

    async def test_database_failure_returns_no_ack_and_reconnects_the_same_client(self) -> None:
        ingestor = _FakeIngestor()
        ingestor.ingest.side_effect = RuntimeError('database unavailable')
        subscriber = _subscriber(ingestor=ingestor)

        with self.assertRaisesRegex(RuntimeError, 'database unavailable'):
            await subscriber.on_message(subscriber.client, TELEMETRY_PATH, b'{}', 1, {'retain': False})

        self.assertIsNotNone(subscriber._reconnect_task)
        await subscriber._reconnect_task
        subscriber.client.reconnect.assert_awaited_once_with(delay=True)

    async def test_ignores_topics_outside_the_device_contract(self) -> None:
        ingestor = _FakeIngestor()
        subscriber = _subscriber(ingestor=ingestor)

        ack = await subscriber.on_message(subscriber.client, 'liteboard/not-a-uuid/devices/x/telemetry', b'{}', 1, {})

        self.assertEqual(SUCCESSFUL_PUBACK, ack)
        ingestor.ingest.assert_not_awaited()


class _FakeIngestor:
    def __init__(self) -> None:
        self.ingest = AsyncMock(return_value=IngestResult('persisted'))


class _FakeMqttClient:
    def __init__(self, client_id: str, **options: object) -> None:
        self.client_id = client_id
        self.options = options
        self.credentials: tuple[str, str] | None = None
        self.connection: tuple[str, int, object, int] | None = None
        self.subscriptions: list[tuple[str, int, bool]] = []
        self.on_connect = None
        self.on_disconnect = None
        self.on_message = None
        self.reconnect = AsyncMock()
        self.disconnect = AsyncMock()

    def set_auth_credentials(self, username: str, password: str) -> None:
        self.credentials = (username, password)

    async def connect(self, host: str, port: int, *, ssl: object, version: int) -> None:
        self.connection = (host, port, ssl, version)
        self.on_connect(self, 0, 0, {})

    def subscribe(self, topic: str, *, qos: int, retain_as_published: bool) -> None:
        self.subscriptions.append((topic, qos, retain_as_published))


def _subscriber(
    *,
    ingestor: _FakeIngestor | None = None,
    heartbeat_pipeline: list[object] | None = None,
) -> MqttSubscriber:
    with patch('app.ingress.mqtt_subscriber.MQTTClient', _FakeMqttClient):
        return MqttSubscriber(
            heartbeat_pipeline=heartbeat_pipeline or [],
            telemetry_ingestor=ingestor or _FakeIngestor(),
        )
