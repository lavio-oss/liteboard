from __future__ import annotations

import os
from contextlib import asynccontextmanager
from datetime import datetime, timezone

import asyncpg
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from app.ingress.mqtt_subscriber import MqttSubscriber
from app.pipeline.stages import MarkPresence, decode_json
from app.storage.presence import PostgresPresenceWriter
from app.storage.telemetry_ingest import PostgresTelemetryIngestor


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

    mqtt_subscriber = MqttSubscriber(
        heartbeat_pipeline=[decode_json, MarkPresence(PostgresPresenceWriter(db_pool))],
        telemetry_ingestor=PostgresTelemetryIngestor(db_pool),
    )
    application.state.mqtt_subscriber = mqtt_subscriber

    try:
        await mqtt_subscriber.connect_and_subscribe()
        application.state.mqtt_client = mqtt_subscriber.client
        yield
    finally:
        await mqtt_subscriber.disconnect()
        await db_pool.close()


app = FastAPI(title='LiteBoard Telemetry Service', version='0.1.0', lifespan=lifespan)


@app.get('/health')
async def health() -> dict[str, str]:
    return {'service': 'liteboard-telemetry-service', 'status': 'ok'}


@app.get('/ready', response_model=None)
async def ready(request: Request) -> dict[str, str] | JSONResponse:
    postgres_ready = False
    mqtt_ready = request.app.state.mqtt_subscriber.is_connected
    try:
        postgres_ready = await request.app.state.db_pool.fetchval('SELECT 1') == 1
    except Exception:
        postgres_ready = False

    if not postgres_ready or not mqtt_ready:
        return JSONResponse(
            status_code=503,
            content={
                'service': 'liteboard-telemetry-service',
                'status': 'not_ready',
                'dependencies': {'postgres': postgres_ready, 'mqtt': mqtt_ready},
                'checked_at': datetime.now(timezone.utc).isoformat(),
            },
        )

    return {
        'service': 'liteboard-telemetry-service',
        'status': 'ready',
        'dependencies': {'postgres': 'ready', 'mqtt': 'ready'},
        'checked_at': datetime.now(timezone.utc).isoformat(),
    }
