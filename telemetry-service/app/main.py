from __future__ import annotations

import os
from contextlib import asynccontextmanager
from datetime import datetime, timezone

import asyncpg
from fastapi import FastAPI

from app.ingress.mqtt_subscriber import MqttSubscriber
from app.pipeline.stages import MarkPresence, ValidateSchema, decode_json
from app.storage.device_schema import PostgresDeviceSchemaReader
from app.storage.presence import PostgresPresenceWriter


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

    mark_presence = MarkPresence(PostgresPresenceWriter(db_pool))
    mqtt_subscriber = MqttSubscriber({
        'heartbeat': [decode_json, mark_presence],
        'telemetry': [decode_json, mark_presence, ValidateSchema(PostgresDeviceSchemaReader(db_pool))],
    })
    await mqtt_subscriber.connect_and_subscribe()
    application.state.mqtt_client = mqtt_subscriber.client

    try:
        yield
    finally:
        await mqtt_subscriber.client.disconnect()
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
