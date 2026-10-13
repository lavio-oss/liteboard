from __future__ import annotations

import asyncio
import logging
import os
from collections.abc import Mapping, Sequence
from typing import Any
from uuid import UUID

from gmqtt import Client as MQTTClient
from gmqtt.mqtt.constants import MQTTv50

from app.mqtt_tls import build_mqtt_ssl_context
from app.pipeline.context import MessageContext
from app.pipeline.runner import Stage, run_pipeline
from app.storage.telemetry_ingest import IngestResult, PostgresTelemetryIngestor

TELEMETRY_TOPIC = 'liteboard/+/devices/+/telemetry'
HEARTBEAT_TOPIC = 'liteboard/+/devices/+/heartbeat'
SUCCESSFUL_PUBACK = 0
LOGGER = logging.getLogger(__name__)


def parse_device_topic(topic: str) -> tuple[str, str, str] | None:
    parts = topic.split('/')
    if len(parts) != 5:
        return None

    prefix, project_id, resource, device_id, channel = parts
    if prefix != 'liteboard' or resource != 'devices' or channel not in {'telemetry', 'heartbeat'}:
        return None

    try:
        UUID(project_id)
        UUID(device_id)
    except ValueError:
        return None

    return project_id, device_id, channel


class MqttSubscriber:
    def __init__(
        self,
        *,
        heartbeat_pipeline: Sequence[Stage],
        telemetry_ingestor: PostgresTelemetryIngestor,
    ) -> None:
        session_expiry_interval = int(os.getenv('MQTT_SESSION_EXPIRY_SECONDS', '2147483647'))
        receive_maximum = int(os.getenv('MQTT_RECEIVE_MAXIMUM', '20'))
        if not 1 <= session_expiry_interval <= 0xFFFFFFFF:
            raise ValueError('MQTT_SESSION_EXPIRY_SECONDS must be between 1 and 4294967295.')
        if not 1 <= receive_maximum <= 0xFFFF:
            raise ValueError('MQTT_RECEIVE_MAXIMUM must be between 1 and 65535.')

        self._heartbeat_pipeline = heartbeat_pipeline
        self._telemetry_ingestor = telemetry_ingestor
        self._is_connected = False
        self._reconnect_task: asyncio.Task[None] | None = None
        self.client = MQTTClient(
            os.getenv('MQTT_CLIENT_ID', 'liteboard-telemetry-service'),
            clean_session=False,
            optimistic_acknowledgement=False,
            session_expiry_interval=session_expiry_interval,
            receive_maximum=receive_maximum,
        )
        self.client.on_connect = self.on_connect
        self.client.on_disconnect = self.on_disconnect
        self.client.on_message = self.on_message

    @property
    def is_connected(self) -> bool:
        return self._is_connected

    async def connect_and_subscribe(self) -> None:
        mqtt_username = os.getenv('MQTT_USERNAME', '')
        mqtt_password = os.getenv('MQTT_PASSWORD', '')
        if mqtt_username:
            self.client.set_auth_credentials(mqtt_username, mqtt_password)

        mqtt_tls_context = build_mqtt_ssl_context(os.getenv('MQTT_TLS_CA_FILE', '/run/secrets/mqtt_tls_ca_certificate'))
        await self.client.connect(
            os.getenv('MQTT_HOST', 'localhost'),
            int(os.getenv('MQTT_PORT', '8883')),
            ssl=mqtt_tls_context,
            version=MQTTv50,
        )

    def on_connect(self, client: MQTTClient, session_present: int, result: int, _properties: Any) -> None:
        if result != 0:
            self._is_connected = False
            LOGGER.error('mqtt_connection_rejected', extra={'reason_code': result})
            return

        self._is_connected = True
        if not session_present:
            client.subscribe(TELEMETRY_TOPIC, qos=1, retain_as_published=True)
            client.subscribe(HEARTBEAT_TOPIC, qos=1, retain_as_published=True)

    def on_disconnect(
        self,
        _client: MQTTClient,
        _packet: Any,
        _exception: Exception | None = None,
    ) -> None:
        self._is_connected = False

    async def on_message(
        self,
        _client: MQTTClient,
        topic: str,
        payload: bytes,
        _qos: int,
        properties: Mapping[str, Any] | None,
    ) -> int:
        parsed = parse_device_topic(topic)
        if parsed is None:
            return SUCCESSFUL_PUBACK

        project_id, device_id, channel = parsed
        try:
            if channel == 'telemetry':
                result = await self._telemetry_ingestor.ingest(
                    project_id=project_id,
                    device_id=device_id,
                    raw=payload,
                    retained=properties is not None and bool(properties.get('retain', False)),
                )
                self._log_telemetry_outcome(
                    project_id=project_id,
                    device_id=device_id,
                    payload_size=len(payload),
                    result=result,
                )
            else:
                await run_pipeline(
                    self._heartbeat_pipeline,
                    MessageContext(project_id=project_id, device_id=device_id, channel=channel, raw=payload),
                )
        except Exception:
            LOGGER.exception(
                'mqtt_message_processing_failed',
                extra={
                    'project_id': project_id,
                    'device_id': device_id,
                    'channel': channel,
                    'payload_size': len(payload),
                },
            )
            self._schedule_reconnect()
            raise

        return SUCCESSFUL_PUBACK

    async def disconnect(self) -> None:
        if self._reconnect_task is not None and not self._reconnect_task.done():
            self._reconnect_task.cancel()
            try:
                await self._reconnect_task
            except asyncio.CancelledError:
                pass
        await self.client.disconnect()

    def _schedule_reconnect(self) -> None:
        if self._reconnect_task is None or self._reconnect_task.done():
            self._reconnect_task = asyncio.create_task(self._reconnect_after_ingest_failure())

    async def _reconnect_after_ingest_failure(self) -> None:
        try:
            await self.client.reconnect(delay=True)
        except Exception:
            LOGGER.exception('mqtt_reconnect_failed')

    @staticmethod
    def _log_telemetry_outcome(
        *,
        project_id: str,
        device_id: str,
        payload_size: int,
        result: IngestResult,
    ) -> None:
        LOGGER.info(
            'telemetry_ingest_outcome',
            extra={
                'project_id': project_id,
                'device_id': device_id,
                'channel': 'telemetry',
                'payload_size': payload_size,
                'outcome': result.status,
                'reasons': [{'code': reason.code, 'field': reason.field} for reason in result.reasons],
            },
        )
