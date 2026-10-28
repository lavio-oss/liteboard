from __future__ import annotations

import asyncio
import logging
import os
from collections.abc import Mapping, Sequence
from datetime import datetime, timezone
from typing import Any
from uuid import UUID

from gmqtt import Client as MQTTClient
from gmqtt.mqtt.constants import MQTTv50

from app.ingress.gateway import GATEWAY_ACTIONS, GatewayHandler
from app.mqtt_tls import build_mqtt_ssl_context
from app.pipeline.context import MessageContext
from app.pipeline.runner import Stage, run_pipeline
from app.realtime.hub import RealtimeHub, outcome_event, sample_event
from app.realtime.presence_hub import PresenceHub
from app.storage.telemetry_ingest import IngestResult, PostgresTelemetryIngestor

TELEMETRY_TOPIC = 'liteboard/+/devices/+/telemetry'
HEARTBEAT_TOPIC = 'liteboard/+/devices/+/heartbeat'
GATEWAY_TOPIC = 'liteboard/+/devices/+/gateway/+'
SUCCESSFUL_PUBACK = 0
MAX_PENDING_BROADCAST_RECORDS = 256
LOGGER = logging.getLogger(__name__)


def parse_device_topic(topic: str) -> tuple[str, str, str] | None:
    """Returns (project_id, device_id, channel); gateway channels come back as 'gateway/<action>'."""
    parts = topic.split('/')
    if len(parts) == 6 and parts[4] == 'gateway' and parts[5] in GATEWAY_ACTIONS:
        parts = [*parts[:4], f'gateway/{parts[5]}']
    if len(parts) != 5:
        return None

    prefix, project_id, resource, device_id, channel = parts
    if prefix != 'liteboard' or resource != 'devices' or not (channel in {'telemetry', 'heartbeat'} or channel.startswith('gateway/')):
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
        gateway_handler: GatewayHandler,
        realtime_hub: RealtimeHub,
        presence_hub: PresenceHub,
    ) -> None:
        session_expiry_interval = int(os.getenv('MQTT_SESSION_EXPIRY_SECONDS', '2147483647'))
        receive_maximum = int(os.getenv('MQTT_RECEIVE_MAXIMUM', '20'))
        if not 1 <= session_expiry_interval <= 0xFFFFFFFF:
            raise ValueError('MQTT_SESSION_EXPIRY_SECONDS must be between 1 and 4294967295.')
        if not 1 <= receive_maximum <= 0xFFFF:
            raise ValueError('MQTT_RECEIVE_MAXIMUM must be between 1 and 65535.')

        self._heartbeat_pipeline = heartbeat_pipeline
        self._telemetry_ingestor = telemetry_ingestor
        self._gateway_handler = gateway_handler
        self._realtime_hub = realtime_hub
        self._presence_hub = presence_hub
        self._broadcast_records: set[asyncio.Task[None]] = set()
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
            client.subscribe(GATEWAY_TOPIC, qos=1, retain_as_published=True)

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
        received_wall = datetime.now(timezone.utc)
        parsed = parse_device_topic(topic)
        if parsed is None:
            # Only the trace record is at stake here, never telemetry, so a failed write is logged and acked.
            try:
                processing_id = await self._telemetry_ingestor.record_invalid_topic(
                    topic=topic,
                    payload_size=len(payload),
                    received_wall=received_wall,
                )
                LOGGER.info('telemetry_invalid_topic', extra={'processing_id': processing_id})
            except Exception:
                LOGGER.exception('telemetry_invalid_topic_record_failed', extra={'payload_size': len(payload)})
            return SUCCESSFUL_PUBACK

        project_id, device_id, channel = parsed
        try:
            if channel == 'telemetry':
                result = await self._telemetry_ingestor.ingest(
                    project_id=project_id,
                    device_id=device_id,
                    raw=payload,
                    retained=properties is not None and bool(properties.get('retain', False)),
                    received_wall=received_wall,
                )
                self._broadcast(result)
                self._log_telemetry_outcome(
                    project_id=project_id,
                    device_id=device_id,
                    payload_size=len(payload),
                    result=result,
                )
            elif channel.startswith('gateway/'):
                results = await self._gateway_handler.handle(
                    project_id=project_id,
                    gateway_id=device_id,
                    action=channel.removeprefix('gateway/'),
                    topic=topic,
                    raw=payload,
                    retained=properties is not None and bool(properties.get('retain', False)),
                    received_wall=received_wall,
                )
                for result in results:
                    self._broadcast(result)
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

    def _broadcast(self, result: IngestResult) -> None:
        # Runs after COMMIT; delivery is best-effort because clients recover gaps over REST.
        delivered = 0
        if result.presence is not None:
            self._presence_hub.publish_device_presence(result.presence)
        if result.outcome is not None:
            delivered = self._realtime_hub.publish(
                result.outcome.project_id,
                result.outcome.device_id,
                outcome_event(result.outcome),
            )
        if result.sample is not None:
            delivered = max(
                delivered,
                self._realtime_hub.publish(
                    result.sample.project_id,
                    result.sample.device_id,
                    sample_event(result.sample),
                ),
            )
        if delivered == 0:
            return
        # shortcut: broadcast stages beyond this many in-flight writes are dropped, move to a batched writer if it happens under load.
        if len(self._broadcast_records) >= MAX_PENDING_BROADCAST_RECORDS:
            LOGGER.warning('telemetry_broadcast_record_dropped', extra={'processing_id': result.processing_id})
            return
        task = asyncio.create_task(self._record_broadcast(result.processing_id, delivered))
        self._broadcast_records.add(task)
        task.add_done_callback(self._broadcast_records.discard)

    async def _record_broadcast(self, processing_id: str, delivered: int) -> None:
        try:
            await self._telemetry_ingestor.record_broadcast(processing_id, delivered)
        except Exception:
            LOGGER.exception('telemetry_broadcast_record_failed', extra={'processing_id': processing_id})

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
                'processing_id': result.processing_id,
                'reasons': [{'code': reason.code, 'field': reason.field} for reason in result.reasons],
            },
        )
