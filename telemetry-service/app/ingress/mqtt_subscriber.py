from __future__ import annotations

import os
from collections.abc import Sequence
from typing import Any
from uuid import UUID

from gmqtt import Client as MQTTClient

from app.mqtt_tls import build_mqtt_ssl_context
from app.pipeline.context import MessageContext
from app.pipeline.runner import Stage, run_pipeline

TELEMETRY_TOPIC = 'liteboard/+/devices/+/telemetry'
HEARTBEAT_TOPIC = 'liteboard/+/devices/+/heartbeat'


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
    def __init__(self, pipelines: dict[str, Sequence[Stage]]) -> None:
        self._pipelines = pipelines
        # shortcut: PUBACK is sent before processing and clean_session drops queued messages while offline;
        # IT-3 persistence needs optimistic_acknowledgement=False (on_message returns 0) and clean_session=False.
        self.client = MQTTClient('liteboard-telemetry-service')
        self.client.on_message = self.on_message

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
        )
        self.client.subscribe(TELEMETRY_TOPIC, qos=1)
        self.client.subscribe(HEARTBEAT_TOPIC, qos=1)

    async def on_message(
        self,
        _client: MQTTClient,
        topic: str,
        payload: bytes,
        _qos: int,
        _properties: Any,
    ) -> None:
        parsed = parse_device_topic(topic)
        if parsed is None:
            return

        project_id, device_id, channel = parsed
        await run_pipeline(
            self._pipelines[channel],
            MessageContext(project_id=project_id, device_id=device_id, channel=channel, raw=payload),
        )
