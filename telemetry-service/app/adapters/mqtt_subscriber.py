from __future__ import annotations

import json
import os
from typing import Any
from uuid import UUID

from gmqtt import Client as MQTTClient

from app.application.update_device_presence import UpdateDevicePresence
from app.application.validate_device_telemetry import ValidateDeviceTelemetry
from app.mqtt_tls import build_mqtt_ssl_context

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


def decode_json_object(payload: bytes) -> dict[str, Any] | None:
    if not payload:
        return None

    try:
        decoded = json.loads(payload.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return None

    return decoded if isinstance(decoded, dict) else None


class MqttSubscriber:
    def __init__(
        self,
        update_device_presence: UpdateDevicePresence,
        validate_device_telemetry: ValidateDeviceTelemetry,
    ) -> None:
        self._update_device_presence = update_device_presence
        self._validate_device_telemetry = validate_device_telemetry
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
        decoded = decode_json_object(payload)
        if decoded is None:
            return

        await self._update_device_presence.execute(
            project_id=project_id,
            device_id=device_id,
            channel=channel,
            payload_size=len(payload),
        )

        if channel == 'telemetry':
            await self._validate_device_telemetry.execute(
                project_id=project_id,
                device_id=device_id,
                payload=decoded,
            )
