from __future__ import annotations

import os
import unittest
from unittest.mock import AsyncMock, patch

from app.adapters.mqtt_subscriber import (
    HEARTBEAT_TOPIC,
    TELEMETRY_TOPIC,
    MqttSubscriber,
    decode_json_object,
    parse_device_topic,
)

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


class DecodeJsonObjectTest(unittest.TestCase):
    def test_decodes_json_objects_including_empty_object(self) -> None:
        self.assertEqual({'temperature': 22.5}, decode_json_object(b'{"temperature": 22.5}'))
        self.assertEqual({}, decode_json_object(b'{}'))

    def test_rejects_empty_invalid_utf8_invalid_json_and_non_objects(self) -> None:
        for payload in (b'', b'\xff', b'{invalid', b'[]', b'null', b'42'):
            with self.subTest(payload=payload):
                self.assertIsNone(decode_json_object(payload))


class MqttSubscriberTest(unittest.IsolatedAsyncioTestCase):
    async def test_connects_with_current_auth_tls_defaults_and_qos_one_subscriptions(self) -> None:
        handler = unittest.mock.MagicMock()
        handler.execute = AsyncMock()
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
            patch('app.adapters.mqtt_subscriber.MQTTClient', _FakeMqttClient),
            patch('app.adapters.mqtt_subscriber.build_mqtt_ssl_context', return_value=tls_context) as build_ssl,
        ):
            subscriber = MqttSubscriber(handler, _validator())
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
        handler = unittest.mock.MagicMock()
        with (
            patch.dict(os.environ, {'MQTT_TLS_CA_FILE': '/run/secrets/ca.crt'}, clear=True),
            patch('app.adapters.mqtt_subscriber.MQTTClient', _FakeMqttClient),
            patch('app.adapters.mqtt_subscriber.build_mqtt_ssl_context', return_value=object()),
        ):
            subscriber = MqttSubscriber(handler, _validator())
            await subscriber.connect_and_subscribe()

        self.assertIsNone(subscriber.client.credentials)

    async def test_callback_skips_invalid_messages_and_forwards_valid_metadata(self) -> None:
        handler = unittest.mock.MagicMock()
        handler.execute = AsyncMock()
        subscriber = MqttSubscriber(handler, _validator())
        topic = f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/telemetry'

        with patch('builtins.print') as print_mock:
            await subscriber.on_message(subscriber.client, topic, b'{invalid', 1, object())
            handler.execute.assert_not_awaited()

            await subscriber.on_message(subscriber.client, topic, b'{}', 2, object())

        handler.execute.assert_awaited_once_with(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            channel='telemetry',
            payload_size=2,
        )
        print_mock.assert_not_called()

    async def test_validates_telemetry_after_updating_presence(self) -> None:
        events: list[str] = []
        handler = unittest.mock.MagicMock()
        handler.execute = AsyncMock(side_effect=lambda **_kwargs: events.append('presence'))
        validator = _validator()
        validator.execute.side_effect = lambda **_kwargs: events.append('validate')
        subscriber = MqttSubscriber(handler, validator)
        payload = b'{"values": {"temperature": 200}}'

        await subscriber.on_message(
            subscriber.client,
            f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/telemetry',
            payload,
            1,
            object(),
        )

        self.assertEqual(['presence', 'validate'], events)
        validator.execute.assert_awaited_once_with(
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            payload={'values': {'temperature': 200}},
        )

    async def test_does_not_validate_heartbeats_or_invalid_payloads(self) -> None:
        handler = unittest.mock.MagicMock()
        handler.execute = AsyncMock()
        validator = _validator()
        subscriber = MqttSubscriber(handler, validator)

        await subscriber.on_message(
            subscriber.client,
            f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/heartbeat',
            b'{}',
            1,
            object(),
        )
        await subscriber.on_message(
            subscriber.client,
            f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/telemetry',
            b'[]',
            1,
            object(),
        )

        handler.execute.assert_awaited_once()
        validator.execute.assert_not_awaited()


def _validator() -> unittest.mock.MagicMock:
    validator = unittest.mock.MagicMock()
    validator.execute = AsyncMock()
    return validator


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
