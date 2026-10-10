from __future__ import annotations

import os
import unittest
from unittest.mock import AsyncMock, patch

from app.ingress.mqtt_subscriber import (
    HEARTBEAT_TOPIC,
    TELEMETRY_TOPIC,
    MqttSubscriber,
    parse_device_topic,
)
from app.pipeline.context import MessageContext

PROJECT_ID = '123e4567-e89b-12d3-a456-426614174000'
DEVICE_ID = '123e4567-e89b-12d3-a456-426614174001'


class ParseDeviceTopicTest(unittest.TestCase):
    def test_parses_telemetry_and_heartbeat_topics_without_rewriting_ids(self) -> None:
        self.assertEqual(
            (PROJECT_ID, DEVICE_ID, 'telemetry'),
            parse_device_topic(f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/telemetry'),
        )
        self.assertEqual(
            (PROJECT_ID, DEVICE_ID, 'heartbeat'),
            parse_device_topic(f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/heartbeat'),
        )

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
    async def test_connects_with_current_auth_tls_defaults_and_qos_one_subscriptions(self) -> None:
        tls_context = object()
        with (
            patch.dict(
                os.environ,
                {
                    'MQTT_USERNAME': 'telemetry-service',
                    'MQTT_PASSWORD': 'internal-password',
                    'MQTT_TLS_CA_FILE': '/run/secrets/ca.crt',
                },
                clear=True,
            ),
            patch('app.ingress.mqtt_subscriber.MQTTClient', _FakeMqttClient),
            patch('app.ingress.mqtt_subscriber.build_mqtt_ssl_context', return_value=tls_context) as build_ssl,
        ):
            subscriber = MqttSubscriber({})
            await subscriber.connect_and_subscribe()

        self.assertEqual('liteboard-telemetry-service', subscriber.client.client_id)
        self.assertEqual(('telemetry-service', 'internal-password'), subscriber.client.credentials)
        build_ssl.assert_called_once_with('/run/secrets/ca.crt')
        self.assertEqual(('localhost', 8883, tls_context), subscriber.client.connection)
        self.assertEqual(
            [(TELEMETRY_TOPIC, 1), (HEARTBEAT_TOPIC, 1)],
            subscriber.client.subscriptions,
        )
        self.assertIsNotNone(subscriber.client.on_message)

    async def test_skips_authentication_when_username_is_empty(self) -> None:
        with (
            patch.dict(os.environ, {'MQTT_TLS_CA_FILE': '/run/secrets/ca.crt'}, clear=True),
            patch('app.ingress.mqtt_subscriber.MQTTClient', _FakeMqttClient),
            patch('app.ingress.mqtt_subscriber.build_mqtt_ssl_context', return_value=object()),
        ):
            subscriber = MqttSubscriber({})
            await subscriber.connect_and_subscribe()

        self.assertIsNone(subscriber.client.credentials)

    async def test_runs_the_pipeline_for_the_topic_channel(self) -> None:
        subscriber = MqttSubscriber({'telemetry': ['telemetry-stages'], 'heartbeat': ['heartbeat-stages']})

        with patch('app.ingress.mqtt_subscriber.run_pipeline', new=AsyncMock()) as run_pipeline_mock:
            await subscriber.on_message(
                subscriber.client,
                f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/heartbeat',
                b'{}',
                1,
                object(),
            )

        run_pipeline_mock.assert_awaited_once_with(
            ['heartbeat-stages'],
            MessageContext(project_id=PROJECT_ID, device_id=DEVICE_ID, channel='heartbeat', raw=b'{}'),
        )

    async def test_ignores_topics_outside_the_device_contract(self) -> None:
        subscriber = MqttSubscriber({'telemetry': [], 'heartbeat': []})

        with patch('app.ingress.mqtt_subscriber.run_pipeline', new=AsyncMock()) as run_pipeline_mock:
            await subscriber.on_message(subscriber.client, 'liteboard/not-a-uuid/devices/x/telemetry', b'{}', 1, object())

        run_pipeline_mock.assert_not_awaited()


class _FakeMqttClient:
    def __init__(self, client_id: str) -> None:
        self.client_id = client_id
        self.credentials: tuple[str, str] | None = None
        self.connection: tuple[str, int, object] | None = None
        self.subscriptions: list[tuple[str, int]] = []
        self.on_message = None

    def set_auth_credentials(self, username: str, password: str) -> None:
        self.credentials = (username, password)

    async def connect(self, host: str, port: int, *, ssl: object) -> None:
        self.connection = (host, port, ssl)

    def subscribe(self, topic: str, *, qos: int) -> None:
        self.subscriptions.append((topic, qos))
