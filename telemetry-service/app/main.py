from __future__ import annotations

import asyncio
import os
from contextlib import asynccontextmanager
from datetime import datetime, timezone

import asyncpg
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from app.ingress.mqtt_subscriber import MqttSubscriber
from app.pipeline.stages import MarkPresence, decode_json
from app.realtime.hub import DEFAULT_MAX_CONNECTIONS, RealtimeHub
from app.realtime.presence_hub import PresenceHub
from app.realtime.presence_stream import device_presence_stream
from app.realtime.stream import telemetry_stream
from app.storage.presence import PostgresPresenceWriter
from app.storage.presence_monitor import PostgresDevicePresenceMonitor
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
    ws_secret = os.getenv('TELEMETRY_WS_SECRET', '')
    # WebSocket streaming is refused without the shared secret; ingestion still runs.
    application.state.ws_secret = ws_secret.encode() if len(ws_secret) >= 32 else None
    realtime_hub = RealtimeHub(
        max_connections=int(os.getenv('TELEMETRY_WS_MAX_CONNECTIONS', str(DEFAULT_MAX_CONNECTIONS))),
    )
    presence_hub = PresenceHub(
        max_connections=int(os.getenv('TELEMETRY_WS_MAX_CONNECTIONS', str(DEFAULT_MAX_CONNECTIONS))),
    )
    application.state.realtime_hub = realtime_hub
    application.state.presence_hub = presence_hub
    presence_monitor = PostgresDevicePresenceMonitor(db_pool, presence_hub)
    application.state.presence_monitor = presence_monitor

    mqtt_subscriber = MqttSubscriber(
        heartbeat_pipeline=[decode_json, MarkPresence(PostgresPresenceWriter(db_pool), presence_hub)],
        telemetry_ingestor=PostgresTelemetryIngestor(db_pool),
        realtime_hub=realtime_hub,
        presence_hub=presence_hub,
    )
    application.state.mqtt_subscriber = mqtt_subscriber

    presence_monitor_task: asyncio.Task[None] | None = None
    try:
        await presence_monitor.refresh()
        presence_monitor_task = asyncio.create_task(presence_monitor.run())
        await mqtt_subscriber.connect_and_subscribe()
        application.state.mqtt_client = mqtt_subscriber.client
        yield
    finally:
        if presence_monitor_task is not None:
            presence_monitor_task.cancel()
            try:
                await presence_monitor_task
            except asyncio.CancelledError:
                pass
        await mqtt_subscriber.disconnect()
        await db_pool.close()


app = FastAPI(title='LiteBoard Telemetry Service', version='0.1.0', lifespan=lifespan)
app.add_api_websocket_route('/ws/telemetry', telemetry_stream)
app.add_api_websocket_route('/ws/device-presence', device_presence_stream)


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
