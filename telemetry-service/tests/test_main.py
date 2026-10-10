from __future__ import annotations

import unittest
from datetime import datetime, timezone
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch

from app.main import app, health, lifespan, ready


class EndpointCompatibilityTest(unittest.IsolatedAsyncioTestCase):
    async def test_health_response_is_unchanged(self) -> None:
        self.assertEqual(
            {'service': 'liteboard-telemetry-service', 'status': 'ok'},
            await health(),
        )

    async def test_ready_response_keeps_fields_and_utc_timestamp(self) -> None:
        response = await ready()

        self.assertEqual('liteboard-telemetry-service', response['service'])
        self.assertEqual('ready', response['status'])
        timestamp = datetime.fromisoformat(response['checked_at'])
        self.assertEqual(timezone.utc, timestamp.tzinfo)

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
            async def disconnect(self) -> None:
                events.append('mqtt.disconnect')

        class FakeSubscriber:
            def __init__(self) -> None:
                self.client = FakeMqttClient()

            async def connect_and_subscribe(self) -> None:
                events.append('mqtt.connect_and_subscribe')

        pool = FakePool()
        subscriber = FakeSubscriber()

        async def create_pool(**_kwargs: object) -> FakePool:
            events.append('pool.create')
            return pool

        application = SimpleNamespace(state=SimpleNamespace())
        with (
            patch('app.main.asyncpg.create_pool', new=AsyncMock(side_effect=create_pool)) as create_pool_mock,
            patch('app.main.PostgresPresenceWriter') as writer_factory,
            patch('app.main.UpdateDevicePresence') as handler_factory,
            patch('app.main.PostgresDeviceSchemaReader') as schema_reader_factory,
            patch('app.main.ValidateDeviceTelemetry') as validator_factory,
            patch('app.main.MqttSubscriber', return_value=subscriber) as subscriber_factory,
        ):
            async with lifespan(application):
                self.assertIs(application.state.db_pool, pool)
                self.assertIs(application.state.mqtt_client, subscriber.client)

        self.assertEqual(
            ['pool.create', 'mqtt.connect_and_subscribe', 'mqtt.disconnect', 'pool.close'],
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
        handler_factory.assert_called_once_with(writer_factory.return_value)
        schema_reader_factory.assert_called_once_with(pool)
        validator_factory.assert_called_once_with(schema_reader_factory.return_value)
        subscriber_factory.assert_called_once_with(handler_factory.return_value, validator_factory.return_value)
