from __future__ import annotations

import json
import os
from contextlib import asynccontextmanager
from datetime import datetime, timezone
from typing import Any
from uuid import UUID

import asyncpg
from fastapi import FastAPI
from gmqtt import Client as MQTTClient

from app.mqtt_tls import build_mqtt_ssl_context

TELEMETRY_TOPIC = 'liteboard/+/devices/+/telemetry'
HEARTBEAT_TOPIC = 'liteboard/+/devices/+/heartbeat'

UPDATE_DEVICE_PRESENCE_SQL = """
UPDATE devices
SET connection_state = 'online',
    last_seen_at = NOW(),
    updated_at = NOW()
WHERE id = $1::uuid
  AND project_id = $2::uuid
  AND status = 'active'
"""


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


def is_valid_json_payload(payload: bytes) -> bool:
    if not payload:
        return False

    try:
        decoded = json.loads(payload.decode('utf-8'))
        return isinstance(decoded, dict)
    except (UnicodeDecodeError, json.JSONDecodeError):
        return False


@asynccontextmanager
async def lifespan(application: FastAPI):
    db_pool = await asyncpg.create_pool(
        host=os.getenv('DB_HOST', 'localhost'),
        port=int(os.getenv('DB_PORT', '5432')),
        database=os.getenv('DB_DATABASE', 'liteboard'),
        user=os.getenv('DB_USERNAME', 'liteboard'),
        password=os.getenv('DB_PASSWORD', 'liteboard'),
        min_size=1,
        max_size=5,
    )
    application.state.db_pool = db_pool

    async def on_message(_client: MQTTClient, topic: str, payload: bytes, _qos: int, _properties: Any) -> None:
        parsed = parse_device_topic(topic)
        if parsed is None:
            return

        project_id, device_id, channel = parsed
        if not is_valid_json_payload(payload):
            return

        async with db_pool.acquire() as connection:
            await connection.execute(UPDATE_DEVICE_PRESENCE_SQL, device_id, project_id)

        print(
            {
                'project_id': project_id,
                'device_id': device_id,
                'channel': channel,
                'payload_size': len(payload),
            },
            flush=True,
        )

    client = MQTTClient('liteboard-telemetry-service')
    mqtt_username = os.getenv('MQTT_USERNAME', '')
    mqtt_password = os.getenv('MQTT_PASSWORD', '')
    if mqtt_username:
        client.set_auth_credentials(mqtt_username, mqtt_password)

    client.on_message = on_message
    mqtt_tls_context = build_mqtt_ssl_context(os.getenv('MQTT_TLS_CA_FILE', '/run/secrets/mqtt_tls_ca_certificate'))
    await client.connect(
        os.getenv('MQTT_HOST', 'localhost'),
        int(os.getenv('MQTT_PORT', '8883')),
        ssl=mqtt_tls_context,
    )
    client.subscribe(TELEMETRY_TOPIC, qos=1)
    client.subscribe(HEARTBEAT_TOPIC, qos=1)
    application.state.mqtt_client = client

    try:
        yield
    finally:
        await client.disconnect()
        await db_pool.close()


app = FastAPI(title='LiteBoard Telemetry Service', version='0.1.0', lifespan=lifespan)


@app.get('/health')
async def health() -> dict[str, str]:
    return {'service': 'liteboard-telemetry-service', 'status': 'ok'}


@app.get('/ready')
async def ready() -> dict[str, str]:
    return {
        'service': 'liteboard-telemetry-service',
        'status': 'ready',
        'checked_at': datetime.now(timezone.utc).isoformat(),
    }
