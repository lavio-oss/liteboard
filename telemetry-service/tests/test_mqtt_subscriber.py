from __future__ import annotations

import asyncio
import os
import unittest
from datetime import datetime, timezone
from unittest.mock import ANY, AsyncMock, patch

from app.ingress.mqtt_subscriber import (
    GATEWAY_TOPIC,
    HEARTBEAT_TOPIC,
    SUCCESSFUL_PUBACK,
    TELEMETRY_TOPIC,
    MqttSubscriber,
    parse_device_topic,
)
from app.pipeline.context import MessageContext
from app.realtime.hub import RealtimeHub
from app.realtime.presence_hub import PresenceHub
from app.storage.presence import DevicePresence
from app.storage.telemetry_ingest import IngestResult, PersistedSample, RecordedOutcome
from app.telemetry.validation import Rejection

PROJECT_ID = '123e4567-e89b-12d3-a456-426614174000'
DEVICE_ID = '123e4567-e89b-12d3-a456-426614174001'
TENANT_ID = '123e4567-e89b-12d3-a456-426614174010'
TELEMETRY_PATH = f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/telemetry'
HEARTBEAT_PATH = f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/heartbeat'


class ParseDeviceTopicTest(unittest.TestCase):
    def test_parses_telemetry_and_heartbeat_topics_without_rewriting_ids(self) -> None:
        self.assertEqual((PROJECT_ID, DEVICE_ID, 'telemetry'), parse_device_topic(TELEMETRY_PATH))
        self.assertEqual((PROJECT_ID, DEVICE_ID, 'heartbeat'), parse_device_topic(HEARTBEAT_PATH))

    def test_parses_gateway_topics_and_rejects_unknown_gateway_actions(self) -> None:
        for action in ('connect', 'telemetry', 'disconnect'):
            with self.subTest(action=action):
                self.assertEqual(
                    (PROJECT_ID, DEVICE_ID, f'gateway/{action}'),
                    parse_device_topic(f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/gateway/{action}'),
                )
        self.assertIsNone(parse_device_topic(f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/gateway/rpc'))
        self.assertIsNone(parse_device_topic(f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/gateway/telemetry/x'))

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
            [(TELEMETRY_TOPIC, 1, True), (HEARTBEAT_TOPIC, 1, True), (GATEWAY_TOPIC, 1, True)],
            subscriber.client.subscriptions,
        )
        self.assertTrue(subscriber.is_connected)

    async def test_subscribes_again_only_when_the_broker_has_no_persistent_session(self) -> None:
        subscriber = _subscriber()
        subscriber.on_connect(subscriber.client, 1, 0, {})
        self.assertEqual([], subscriber.client.subscriptions)

        subscriber.on_connect(subscriber.client, 0, 0, {})

        self.assertEqual(
            [(TELEMETRY_TOPIC, 1, True), (HEARTBEAT_TOPIC, 1, True), (GATEWAY_TOPIC, 1, True)],
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
            return IngestResult('persisted', 'processing-1')

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

    async def test_publishes_presence_only_after_ingestion_returns_from_commit(self) -> None:
        committed = False
        ingestor = _FakeIngestor()

        class CommitCheckingPresenceHub(PresenceHub):
            def publish_device_presence(self, presence: DevicePresence) -> int:
                if not committed:
                    raise AssertionError('Presence must be published after the ingest commit.')
                return super().publish_device_presence(presence)

        presence_hub = CommitCheckingPresenceHub()
        watcher = presence_hub.subscribe(TENANT_ID)
        other_tenant = presence_hub.subscribe('123e4567-e89b-12d3-a456-426614174011')
        presence = DevicePresence(
            tenant_id=TENANT_ID,
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            connection_state='online',
            last_seen_at=datetime(2026, 10, 15, 10, 0, tzinfo=timezone.utc),
            updated_at=datetime(2026, 10, 15, 10, 0, tzinfo=timezone.utc),
        )

        async def ingest(**_kwargs: object) -> IngestResult:
            nonlocal committed
            committed = True
            return IngestResult('rejected', 'processing-1', presence=presence)

        ingestor.ingest.side_effect = ingest
        subscriber = _subscriber(ingestor=ingestor, presence_hub=presence_hub)

        await subscriber.on_message(subscriber.client, TELEMETRY_PATH, b'{}', 1, {'retain': False})

        self.assertTrue(committed)
        event = watcher.queue.get_nowait()
        self.assertEqual('device.presence', event['type'])
        self.assertEqual(DEVICE_ID, event['data']['device_id'])
        self.assertEqual(PROJECT_ID, event['data']['project_id'])
        self.assertEqual('online', event['data']['connection_state'])
        self.assertEqual('2026-10-15T10:00:00Z', event['data']['last_seen_at'])
        self.assertTrue(other_tenant.queue.empty())

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
            received_wall=ANY,
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

    async def test_publishes_committed_samples_to_device_subscribers_and_records_the_broadcast(self) -> None:
        ingestor = _FakeIngestor()
        sample = PersistedSample(
            id='sample-1',
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            device_profile_version_id='version-1',
            message_id='msg-1',
            occurred_at=datetime(2026, 10, 13, 10, 0, tzinfo=timezone.utc),
            received_at=datetime(2026, 10, 13, 10, 0, 1, tzinfo=timezone.utc),
            values={'temperature': 40},
        )
        processing_id = '123e4567-e89b-12d3-a456-426614174003'
        outcome = RecordedOutcome(
            id='123e4567-e89b-12d3-a456-426614174002',
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            processing_id=processing_id,
            message_id='msg-1',
            status='persisted',
            reason_codes=(),
            stages=(
                {'stage': 'received', 'at': '2026-10-13T10:00:01+00:00'},
                {'stage': 'commit', 'at': '2026-10-13T10:00:01+00:00'},
            ),
            received_at=datetime(2026, 10, 13, 10, 0, 1, tzinfo=timezone.utc),
        )
        ingestor.ingest.return_value = IngestResult('persisted', processing_id, sample=sample, outcome=outcome)
        hub = RealtimeHub()
        watcher = hub.subscribe(PROJECT_ID, DEVICE_ID)
        other = hub.subscribe(PROJECT_ID, '123e4567-e89b-12d3-a456-426614174099')
        subscriber = _subscriber(ingestor=ingestor, hub=hub)

        ack = await subscriber.on_message(subscriber.client, TELEMETRY_PATH, b'{}', 1, {'retain': False})
        await asyncio.gather(*subscriber._broadcast_records)

        self.assertEqual(SUCCESSFUL_PUBACK, ack)
        outcome_event = watcher.queue.get_nowait()
        self.assertEqual('telemetry.outcome', outcome_event['type'])
        self.assertEqual(processing_id, outcome_event['data']['processing_id'])
        self.assertEqual('persisted', outcome_event['data']['status'])
        sample_event = watcher.queue.get_nowait()
        self.assertEqual('telemetry.sample', sample_event['type'])
        self.assertEqual('sample-1', sample_event['data']['id'])
        self.assertEqual('2026-10-13T10:00:00.000000Z', sample_event['data']['occurred_at'])
        self.assertTrue(other.queue.empty())
        ingestor.record_broadcast.assert_awaited_once_with(processing_id, 1)

    async def test_broadcasts_skipped_outcomes_without_a_sample(self) -> None:
        processing_id = '123e4567-e89b-12d3-a456-426614174003'
        outcome = RecordedOutcome(
            id='123e4567-e89b-12d3-a456-426614174002',
            project_id=PROJECT_ID,
            device_id=DEVICE_ID,
            processing_id=processing_id,
            message_id=None,
            status='skipped',
            reason_codes=(Rejection('schema_not_assigned'),),
            stages=({'stage': 'received', 'at': '2026-10-13T10:00:01+00:00'}, {'stage': 'skipped', 'at': '2026-10-13T10:00:01+00:00'}, {'stage': 'commit', 'at': '2026-10-13T10:00:01+00:00'}),
            received_at=datetime(2026, 10, 13, 10, 0, 1, tzinfo=timezone.utc),
        )
        ingestor = _FakeIngestor()
        ingestor.ingest.return_value = IngestResult('skipped', processing_id, outcome.reason_codes, outcome=outcome)
        hub = RealtimeHub()
        watcher = hub.subscribe(PROJECT_ID, DEVICE_ID)
        subscriber = _subscriber(ingestor=ingestor, hub=hub)

        await subscriber.on_message(subscriber.client, TELEMETRY_PATH, b'{"values":{}}', 1, {'retain': False})
        await asyncio.gather(*subscriber._broadcast_records)

        event = watcher.queue.get_nowait()
        self.assertEqual('telemetry.outcome', event['type'])
        self.assertEqual('skipped', event['data']['status'])
        self.assertEqual([{'code': 'schema_not_assigned', 'field': None}], event['data']['reason_codes'])
        self.assertTrue(watcher.queue.empty())
        ingestor.record_broadcast.assert_awaited_once_with(processing_id, 1)

    async def test_routes_gateway_messages_to_the_gateway_handler_and_broadcasts_each_result(self) -> None:
        ingestor = _FakeIngestor()
        gateway_handler = _FakeGatewayHandler()
        gateway_handler.handle.return_value = [IngestResult('persisted', 'p-1'), IngestResult('rejected', 'p-2')]
        subscriber = _subscriber(ingestor=ingestor, gateway_handler=gateway_handler)
        topic = f'liteboard/{PROJECT_ID}/devices/{DEVICE_ID}/gateway/telemetry'

        with patch.object(subscriber, '_broadcast') as broadcast:
            ack = await subscriber.on_message(subscriber.client, topic, b'{}', 1, {'retain': False})

        self.assertEqual(SUCCESSFUL_PUBACK, ack)
        ingestor.ingest.assert_not_awaited()
        gateway_handler.handle.assert_awaited_once_with(
            project_id=PROJECT_ID,
            gateway_id=DEVICE_ID,
            action='telemetry',
            topic=topic,
            raw=b'{}',
            retained=False,
            received_wall=ANY,
        )
        self.assertEqual(2, broadcast.call_count)

    async def test_ignores_topics_outside_the_device_contract(self) -> None:
        ingestor = _FakeIngestor()
        subscriber = _subscriber(ingestor=ingestor)

        ack = await subscriber.on_message(subscriber.client, 'liteboard/not-a-uuid/devices/x/telemetry', b'{}', 1, {})

        self.assertEqual(SUCCESSFUL_PUBACK, ack)
        ingestor.ingest.assert_not_awaited()
        ingestor.record_invalid_topic.assert_awaited_once_with(
            topic='liteboard/not-a-uuid/devices/x/telemetry',
            payload_size=2,
            received_wall=ANY,
        )

    async def test_invalid_topic_is_still_acked_when_its_trace_record_fails(self) -> None:
        ingestor = _FakeIngestor()
        ingestor.record_invalid_topic.side_effect = RuntimeError('database unavailable')
        subscriber = _subscriber(ingestor=ingestor)

        ack = await subscriber.on_message(subscriber.client, 'liteboard/x/devices/y/telemetry', b'{}', 1, {})

        self.assertEqual(SUCCESSFUL_PUBACK, ack)


class _FakeIngestor:
    def __init__(self) -> None:
        self.ingest = AsyncMock(return_value=IngestResult('persisted', 'processing-1'))
        self.record_broadcast = AsyncMock()
        self.record_invalid_topic = AsyncMock(return_value='processing-invalid')


class _FakeGatewayHandler:
    def __init__(self) -> None:
        self.handle = AsyncMock(return_value=[])


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
    hub: RealtimeHub | None = None,
    presence_hub: PresenceHub | None = None,
    gateway_handler: object | None = None,
) -> MqttSubscriber:
    with patch('app.ingress.mqtt_subscriber.MQTTClient', _FakeMqttClient):
        return MqttSubscriber(
            heartbeat_pipeline=heartbeat_pipeline or [],
            telemetry_ingestor=ingestor or _FakeIngestor(),
            gateway_handler=gateway_handler or _FakeGatewayHandler(),
            realtime_hub=hub or RealtimeHub(),
            presence_hub=presence_hub or PresenceHub(),
        )
