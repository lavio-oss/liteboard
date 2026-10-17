from __future__ import annotations

import asyncio
import json
import os
import unittest
from datetime import datetime, timezone
from typing import Any
from unittest.mock import patch
from uuid import uuid4

import asyncpg
from gmqtt import Client
from gmqtt.mqtt.constants import MQTTv50

from app.ingress.mqtt_subscriber import MqttSubscriber
from app.realtime.hub import RealtimeHub
from app.realtime.presence_hub import PresenceHub
from app.storage.telemetry_ingest import IngestResult, PostgresTelemetryIngestor

MQTT_HOST = os.getenv('TELEMETRY_TEST_MQTT_HOST')
MQTT_PORT = int(os.getenv('TELEMETRY_TEST_MQTT_PORT', '8883'))
MQTT_CA_FILE = os.getenv('TELEMETRY_TEST_MQTT_CA_FILE')
MQTT_USERNAME = os.getenv('TELEMETRY_TEST_MQTT_USERNAME')
MQTT_PASSWORD = os.getenv('TELEMETRY_TEST_MQTT_PASSWORD')
MQTT_READY = all((MQTT_HOST, MQTT_CA_FILE, MQTT_USERNAME, MQTT_PASSWORD))
DATABASE_URL = os.getenv('TELEMETRY_TEST_DATABASE_URL')


@unittest.skipUnless(
    MQTT_READY,
    'Set TELEMETRY_TEST_MQTT_HOST, TELEMETRY_TEST_MQTT_CA_FILE, TELEMETRY_TEST_MQTT_USERNAME and TELEMETRY_TEST_MQTT_PASSWORD to run Mosquitto integration tests.',
)
class MosquittoDeliveryIntegrationTest(unittest.IsolatedAsyncioTestCase):
    @unittest.skipUnless(DATABASE_URL, 'Set TELEMETRY_TEST_DATABASE_URL to run commit-before-ACK integration coverage.')
    async def test_commit_before_lost_ack_redelivery_creates_one_sample(self) -> None:
        pool = await asyncpg.create_pool(DATABASE_URL, min_size=1, max_size=3)
        tenant_id, project_id, device_id = await _seed_scope(pool)
        client_id = f'liteboard-it3-commit-{uuid4()}'
        topic = f'liteboard/{project_id}/devices/{device_id}/telemetry'
        ingestor = _CommitThenFailIngestor(
            PostgresTelemetryIngestor(pool),
            str(project_id),
            str(device_id),
        )
        environment = {
            'MQTT_HOST': MQTT_HOST,
            'MQTT_PORT': str(MQTT_PORT),
            'MQTT_TLS_CA_FILE': MQTT_CA_FILE,
            'MQTT_USERNAME': MQTT_USERNAME,
            'MQTT_PASSWORD': MQTT_PASSWORD,
            'MQTT_CLIENT_ID': client_id,
            'MQTT_SESSION_EXPIRY_SECONDS': '3600',
        }
        subscriber: MqttSubscriber | None = None
        publisher: Client | None = None

        try:
            with patch.dict(os.environ, environment):
                subscriber = MqttSubscriber(heartbeat_pipeline=[], telemetry_ingestor=ingestor, realtime_hub=RealtimeHub(), presence_hub=PresenceHub())
                await subscriber.connect_and_subscribe()

            publisher = Client(f'{client_id}-publisher', clean_session=True)
            publisher.set_auth_credentials(MQTT_USERNAME, MQTT_PASSWORD)
            await publisher.connect(MQTT_HOST, MQTT_PORT, ssl=_ssl_context(MQTT_CA_FILE), version=MQTTv50)
            payload = json.dumps(
                {
                    'message_id': 'it3-commit-before-ack',
                    'schema_version': 1,
                    'occurred_at': datetime.now(timezone.utc).isoformat(),
                    'values': {'temperature': 42},
                },
                separators=(',', ':'),
            ).encode()
            publisher.publish(topic, payload, qos=1)
            await asyncio.wait_for(ingestor.recovered.wait(), timeout=30)

            async with pool.acquire() as connection:
                sample_count = await connection.fetchval(
                    'SELECT COUNT(*) FROM telemetry_samples WHERE project_id = $1::uuid',
                    project_id,
                )
                outcomes = await connection.fetch(
                    'SELECT status, COUNT(*) AS count FROM telemetry_ingest_outcomes '
                    'WHERE project_id = $1::uuid GROUP BY status',
                    project_id,
                )

            self.assertEqual(2, ingestor.calls)
            self.assertEqual(['persisted', 'duplicate'], ingestor.statuses)
            self.assertEqual(1, sample_count)
            self.assertEqual(
                {'persisted': 1, 'duplicate': 1},
                {row['status']: row['count'] for row in outcomes},
            )
        finally:
            if publisher is not None:
                try:
                    await publisher.disconnect()
                except OSError:
                    pass
            if subscriber is not None:
                try:
                    await subscriber.disconnect()
                except OSError:
                    pass
            async with pool.acquire() as connection:
                await connection.execute('DELETE FROM tenants WHERE id = $1::uuid', tenant_id)
            await pool.close()

    async def test_unacked_qos1_message_is_redelivered_after_ingest_failure(self) -> None:
        client_id = f'liteboard-it3-test-{uuid4()}'
        topic = _telemetry_topic()
        topic_parts = topic.split('/')
        ingestor = _FailOnceIngestor(topic_parts[1], topic_parts[3])
        environment = {
            'MQTT_HOST': MQTT_HOST,
            'MQTT_PORT': str(MQTT_PORT),
            'MQTT_TLS_CA_FILE': MQTT_CA_FILE,
            'MQTT_USERNAME': MQTT_USERNAME,
            'MQTT_PASSWORD': MQTT_PASSWORD,
            'MQTT_CLIENT_ID': client_id,
            'MQTT_SESSION_EXPIRY_SECONDS': '3600',
            'MQTT_RECEIVE_MAXIMUM': '2',
        }
        subscriber: MqttSubscriber | None = None
        publisher: Client | None = None

        try:
            with patch.dict(os.environ, environment):
                subscriber = MqttSubscriber(heartbeat_pipeline=[], telemetry_ingestor=ingestor, realtime_hub=RealtimeHub(), presence_hub=PresenceHub())
                await subscriber.connect_and_subscribe()

            publisher = Client(f'{client_id}-publisher', clean_session=True)
            publisher.set_auth_credentials(MQTT_USERNAME, MQTT_PASSWORD)
            await publisher.connect(MQTT_HOST, MQTT_PORT, ssl=_ssl_context(MQTT_CA_FILE), version=MQTTv50)
            publisher.publish(topic, json.dumps({'message_id': 'it3-redelivery'}), qos=1)

            await asyncio.wait_for(ingestor.persisted.wait(), timeout=30)

            self.assertEqual(2, ingestor.calls)
            self.assertEqual([False, False], ingestor.retained_flags)
        finally:
            if publisher is not None:
                try:
                    await publisher.disconnect()
                except OSError:
                    pass
            if subscriber is not None:
                try:
                    await subscriber.disconnect()
                except OSError:
                    pass

    async def test_retained_telemetry_flag_reaches_ingestion_while_heartbeat_is_unchanged(self) -> None:
        client_id = f'liteboard-it3-retain-{uuid4()}'
        telemetry_topic = _telemetry_topic()
        topic_parts = telemetry_topic.split('/')
        project_id, device_id = topic_parts[1], topic_parts[3]
        ingestor = _RecordingIngestor(project_id, device_id)
        heartbeat_topic = f'liteboard/{project_id}/devices/{device_id}/heartbeat'
        heartbeat_received = asyncio.Event()
        environment = {
            'MQTT_HOST': MQTT_HOST,
            'MQTT_PORT': str(MQTT_PORT),
            'MQTT_TLS_CA_FILE': MQTT_CA_FILE,
            'MQTT_USERNAME': MQTT_USERNAME,
            'MQTT_PASSWORD': MQTT_PASSWORD,
            'MQTT_CLIENT_ID': client_id,
        }
        subscriber: MqttSubscriber | None = None
        publisher: Client | None = None

        async def receive_heartbeat(_context: Any) -> None:
            heartbeat_received.set()

        try:
            with patch.dict(os.environ, environment):
                subscriber = MqttSubscriber(
                    heartbeat_pipeline=[receive_heartbeat],
                    telemetry_ingestor=ingestor,
                    realtime_hub=RealtimeHub(),
                    presence_hub=PresenceHub(),
                )
                await subscriber.connect_and_subscribe()

            publisher = Client(f'{client_id}-publisher', clean_session=True)
            publisher.set_auth_credentials(MQTT_USERNAME, MQTT_PASSWORD)
            await publisher.connect(MQTT_HOST, MQTT_PORT, ssl=_ssl_context(MQTT_CA_FILE), version=MQTTv50)
            publisher.publish(telemetry_topic, b'{}', qos=1, retain=True)
            publisher.publish(heartbeat_topic, b'{}', qos=1, retain=True)

            await asyncio.wait_for(ingestor.received.wait(), timeout=10)
            await asyncio.wait_for(heartbeat_received.wait(), timeout=10)

            self.assertEqual([True], ingestor.retained_flags)
            publisher.publish(telemetry_topic, b'', qos=1, retain=True)
            publisher.publish(heartbeat_topic, b'', qos=1, retain=True)
        finally:
            if publisher is not None:
                try:
                    await publisher.disconnect()
                except OSError:
                    pass
            if subscriber is not None:
                try:
                    await subscriber.disconnect()
                except OSError:
                    pass


class _FailOnceIngestor:
    def __init__(self, project_id: str, device_id: str) -> None:
        self.project_id = project_id
        self.device_id = device_id
        self.calls = 0
        self.retained_flags: list[bool] = []
        self.persisted = asyncio.Event()

    async def ingest(
        self,
        *,
        project_id: str,
        device_id: str,
        retained: bool,
        **_kwargs: object,
    ) -> IngestResult:
        if project_id != self.project_id or device_id != self.device_id:
            return IngestResult('unresolved', 'processing-test')

        self.calls += 1
        self.retained_flags.append(retained)
        if self.calls == 1:
            raise RuntimeError('simulated database outage')
        self.persisted.set()
        return IngestResult('persisted', 'processing-test')


class _RecordingIngestor:
    def __init__(self, project_id: str, device_id: str) -> None:
        self.project_id = project_id
        self.device_id = device_id
        self.retained_flags: list[bool] = []
        self.received = asyncio.Event()

    async def ingest(
        self,
        *,
        project_id: str,
        device_id: str,
        retained: bool,
        **_kwargs: object,
    ) -> IngestResult:
        if project_id != self.project_id or device_id != self.device_id:
            return IngestResult('unresolved', 'processing-test')

        self.retained_flags.append(retained)
        self.received.set()
        return IngestResult('rejected', 'processing-test')


class _CommitThenFailIngestor:
    def __init__(self, ingestor: PostgresTelemetryIngestor, project_id: str, device_id: str) -> None:
        self._ingestor = ingestor
        self.project_id = project_id
        self.device_id = device_id
        self.calls = 0
        self.statuses: list[str] = []
        self.recovered = asyncio.Event()

    async def ingest(
        self,
        *,
        project_id: str,
        device_id: str,
        raw: bytes,
        retained: bool,
        received_wall: datetime,
    ) -> IngestResult:
        if project_id != self.project_id or device_id != self.device_id:
            return IngestResult('unresolved', 'processing-test')

        self.calls += 1
        result = await self._ingestor.ingest(
            project_id=project_id,
            device_id=device_id,
            raw=raw,
            retained=retained,
            received_wall=received_wall,
        )
        self.statuses.append(result.status)
        if self.calls == 1:
            raise RuntimeError('simulated worker interruption after database commit')

        self.recovered.set()
        return result


def _telemetry_topic() -> str:
    return f'liteboard/{uuid4()}/devices/{uuid4()}/telemetry'


def _ssl_context(ca_file: str | None):
    import ssl

    return ssl.create_default_context(cafile=ca_file)


async def _seed_scope(pool: asyncpg.Pool) -> tuple[Any, Any, Any]:
    tenant_id = uuid4()
    project_id = uuid4()
    profile_id = uuid4()
    profile_version_id = uuid4()
    device_id = uuid4()
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    definition = {
        'strict_mode': True,
        'fields': {'temperature': {'type': 'float', 'unit': 'celsius', 'required': True, 'max': 150}},
    }
    async with pool.acquire() as connection:
        async with connection.transaction():
            await connection.execute(
                "INSERT INTO tenants (id, name, code, status, created_at, updated_at) "
                "VALUES ($1, 'IT3 MQTT', $2, 'active', $3, $3)",
                tenant_id,
                f'it3-mqtt-{tenant_id.hex}',
                now,
            )
            await connection.execute(
                "INSERT INTO projects (id, tenant_id, name, status, created_at, updated_at) "
                "VALUES ($1, $2, 'IT3 MQTT', 'active', $3, $3)",
                project_id,
                tenant_id,
                now,
            )
            await connection.execute(
                "INSERT INTO device_profiles (id, project_id, code, name, created_at, updated_at) "
                "VALUES ($1, $2, $3, 'IT3 MQTT', $4, $4)",
                profile_id,
                project_id,
                f'it3-mqtt-{profile_id.hex[:12]}',
                now,
            )
            await connection.execute(
                'INSERT INTO device_profile_versions '
                '(id, project_id, device_profile_id, version, schema_code, definition, created_at, updated_at) '
                'VALUES ($1, $2, $3, 1, $4, $5::jsonb, $6, $6)',
                profile_version_id,
                project_id,
                profile_id,
                f'it3-mqtt-{profile_version_id.hex[:12]}-v1',
                json.dumps(definition),
                now,
            )
            await connection.execute(
                "INSERT INTO devices "
                "(id, project_id, device_profile_version_id, name, code, status, approval_status, "
                "connection_state, created_at, updated_at) "
                "VALUES ($1, $2, $3, 'IT3 MQTT', $4, 'active', 'approved', 'offline', $5, $5)",
                device_id,
                project_id,
                profile_version_id,
                f'it3-mqtt-{device_id.hex[:12]}',
                now,
            )
    return tenant_id, project_id, device_id
