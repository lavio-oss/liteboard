from __future__ import annotations

import os
from contextlib import asynccontextmanager
from datetime import datetime, timezone
from typing import Any

from fastapi import FastAPI
from gmqtt import Client as MQTTClient


async def on_message(_client: MQTTClient, topic: str, payload: bytes, _qos: int, _properties: Any) -> None:
    print({'topic': topic, 'payload_size': len(payload)}, flush=True)


@asynccontextmanager
async def lifespan(application: FastAPI):
    client = MQTTClient('liteboard-telemetry-service')
    client.on_message = on_message
    await client.connect(os.getenv('MQTT_HOST', 'localhost'), int(os.getenv('MQTT_PORT', '1883')))
    client.subscribe('liteboard/+/+/telemetry', qos=1)
    application.state.mqtt_client = client
    yield
    await client.disconnect()


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
