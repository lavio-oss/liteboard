from __future__ import annotations

import unittest
import json
from datetime import datetime, timezone
from types import SimpleNamespace
from unittest.mock import ANY, AsyncMock, patch

from fastapi import FastAPI
from fastapi.responses import JSONResponse

from app.main import app, health, lifespan, ready
from app.pipeline.stages import decode_json


class EndpointCompatibilityTest(unittest.IsolatedAsyncioTestCase):
    async def test_health_reports_process_liveness_without_dependency_state(self) -> None:
        self.assertEqual(
            {'service': 'liteboard-telemetry-service', 'status': 'ok'},
            await health(),
        )

    async def test_ready_checks_postgres_and_mqtt_and_returns_a_utc_timestamp(self) -> None:
        request = _request(
            db_pool=SimpleNamespace(fetchval=AsyncMock(return_value=1)),
            mqtt_subscriber=SimpleNamespace(is_connected=True),
        )

        response = await ready(request)

        self.assertEqual('liteboard-telemetry-service', response['service'])
        self.assertEqual('ready', response['status'])
        self.assertEqual({'postgres': 'ready', 'mqtt': 'ready'}, response['dependencies'])
        timestamp = datetime.fromisoformat(response['checked_at'])
        self.assertEqual(timezone.utc, timestamp.tzinfo)
        request.app.state.db_pool.fetchval.assert_awaited_once_with('SELECT 1')

    async def test_ready_returns_503_when_a_dependency_is_unavailable(self) -> None:
        request = _request(
            db_pool=SimpleNamespace(fetchval=AsyncMock(side_effect=RuntimeError('database unavailable'))),
            mqtt_subscriber=SimpleNamespace(is_connected=False),
        )

        response = await ready(request)

        self.assertIsInstance(response, JSONResponse)
        self.assertEqual(503, response.status_code)
        body = json.loads(response.body)
        self.assertEqual('not_ready', body['status'])
        self.assertEqual({'postgres': False, 'mqtt': False}, body['dependencies'])

    def test_health_and_ready_remain_get_routes(self) -> None:
        routes = {
            route.path: route.methods
            for route in app.routes
            if route.path in {'/health', '/ready'}
        }

        self.assertEqual({'/health': {'GET'}, '/ready': {'GET'}}, routes)


class LifespanCompatibilityTest(unittest.IsolatedAsyncioTestCase):
    async def test_composes_dependencies_and_closes_mqtt_before_database_pool(self) -> None:
        events: list[str] = []

        class FakePool:
            async def close(self) -> None:
                events.append('pool.close')

        class FakeMqttClient:
            pass

        class FakeSubscriber:
            def __init__(self) -> None:
                self.client = FakeMqttClient()

            async def connect_and_subscribe(self) -> None:
                events.append('mqtt.connect_and_subscribe')

            async def disconnect(self) -> None:
                events.append('mqtt.disconnect')

        class FakePresenceMonitor:
            async def refresh(self) -> None:
                events.append('presence.refresh')

            async def run(self) -> None:
                return None

        pool = FakePool()
        subscriber = FakeSubscriber()

        async def create_pool(**_kwargs: object) -> FakePool:
            events.append('pool.create')
            return pool

        application = FastAPI()
        with (
            patch.dict('os.environ', {
                'DB_HOST': 'localhost',
                'DB_PORT': '5432',
                'DB_DATABASE': 'liteboard',
                'DB_USERNAME': 'liteboard',
                'DB_PASSWORD': 'liteboard',
            }),
            patch('app.main.asyncpg.create_pool', new=AsyncMock(side_effect=create_pool)) as create_pool_mock,
            patch('app.main.PostgresPresenceWriter') as writer_factory,
            patch('app.main.MarkPresence') as mark_presence_factory,
            patch('app.main.PostgresDevicePresenceMonitor', return_value=FakePresenceMonitor()) as presence_monitor_factory,
            patch('app.main.PostgresTelemetryIngestor') as ingestor_factory,
            patch('app.main.MqttSubscriber', return_value=subscriber) as subscriber_factory,
        ):
            async with lifespan(application):
                self.assertIs(application.state.db_pool, pool)
                self.assertIs(application.state.mqtt_client, subscriber.client)
                self.assertIs(application.state.mqtt_subscriber, subscriber)
                self.assertIsNotNone(application.state.presence_hub)

        self.assertEqual(
            ['pool.create', 'presence.refresh', 'mqtt.connect_and_subscribe', 'mqtt.disconnect', 'pool.close'],
            events,
        )
        create_pool_mock.assert_awaited_once_with(
            host='localhost',
            port=5432,
            database='liteboard',
            user='liteboard',
            password='liteboard',
            min_size=1,
            max_size=5,
        )
        writer_factory.assert_called_once_with(pool)
        mark_presence_factory.assert_called_once_with(writer_factory.return_value, ANY)
        ingestor_factory.assert_called_once_with(pool)
        presence_monitor_factory.assert_called_once_with(pool, ANY)
        subscriber_factory.assert_called_once_with(
            heartbeat_pipeline=[decode_json, mark_presence_factory.return_value],
            telemetry_ingestor=ingestor_factory.return_value,
            realtime_hub=ANY,
            presence_hub=ANY,
        )


def _request(*, db_pool: object, mqtt_subscriber: object) -> SimpleNamespace:
    return SimpleNamespace(app=SimpleNamespace(state=SimpleNamespace(db_pool=db_pool, mqtt_subscriber=mqtt_subscriber)))
