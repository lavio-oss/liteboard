from __future__ import annotations

import unittest
from types import SimpleNamespace
from unittest.mock import AsyncMock, MagicMock

from app.broker_metrics import SYS_DROPPED_TOPIC, SYS_STORED_TOPIC, BrokerMetrics
from app.ingress.mqtt_subscriber import MqttSubscriber


class BrokerMetricsTest(unittest.IsolatedAsyncioTestCase):
    def test_first_dropped_sample_is_a_baseline_and_an_increase_raises_the_alert(self) -> None:
        metrics = BrokerMetrics()

        metrics.observe_sys(SYS_DROPPED_TOPIC, b'7')
        self.assertFalse(metrics.alerts()['messages_dropped'])

        metrics.observe_sys(SYS_DROPPED_TOPIC, b'7')
        self.assertFalse(metrics.alerts()['messages_dropped'])

        with self.assertLogs('app.broker_metrics', level='ERROR') as logs:
            metrics.observe_sys(SYS_DROPPED_TOPIC, b'9')
        self.assertIn('broker_messages_dropped', logs.output[0])
        self.assertTrue(metrics.alerts()['messages_dropped'])
        self.assertIn('liteboard_broker_publish_dropped_total 9', metrics.render())
        self.assertIn('liteboard_ingest_alert{reason="messages_dropped"} 1', metrics.render())

    def test_drop_alert_expires(self) -> None:
        metrics = BrokerMetrics()
        metrics.drop_alert_seconds = 0
        metrics.observe_sys(SYS_DROPPED_TOPIC, b'1')
        with self.assertLogs('app.broker_metrics', level='ERROR'):
            metrics.observe_sys(SYS_DROPPED_TOPIC, b'2')

        self.assertFalse(metrics.alerts()['messages_dropped'])

    def test_queue_depth_alert_follows_the_threshold(self) -> None:
        metrics = BrokerMetrics()

        metrics.observe_sys(SYS_STORED_TOPIC, b'500')
        self.assertFalse(metrics.alerts()['queue_depth_high'])

        with self.assertLogs('app.broker_metrics', level='ERROR'):
            metrics.observe_sys(SYS_STORED_TOPIC, b'501')
        self.assertTrue(metrics.alerts()['queue_depth_high'])

        metrics.observe_sys(SYS_STORED_TOPIC, b'3')
        self.assertFalse(metrics.alerts()['queue_depth_high'])

    def test_non_numeric_sys_payload_is_ignored(self) -> None:
        metrics = BrokerMetrics()
        metrics.observe_sys(SYS_STORED_TOPIC, b'n/a')

        self.assertIsNone(metrics.stored_messages)

    async def test_probe_times_the_auth_and_acl_queries(self) -> None:
        connection = SimpleNamespace(execute=AsyncMock())
        acquire = MagicMock()
        acquire.return_value.__aenter__ = AsyncMock(return_value=connection)
        acquire.return_value.__aexit__ = AsyncMock(return_value=False)
        pool = SimpleNamespace(fetchval=AsyncMock(return_value='ab' * 32), acquire=acquire)
        metrics = BrokerMetrics()

        await metrics.probe(pool)

        self.assertEqual({'auth', 'acl'}, set(metrics.probe_seconds))
        self.assertEqual(2, connection.execute.await_count)
        self.assertIn("'" + 'ab' * 32 + "'::char(64)", connection.execute.await_args_list[0].args[0])

    async def test_probe_refuses_a_hash_that_is_not_sha256_hex(self) -> None:
        pool = SimpleNamespace(fetchval=AsyncMock(return_value="x'; DROP TABLE devices; --"), acquire=MagicMock())
        metrics = BrokerMetrics()

        await metrics.probe(pool)

        pool.acquire.assert_not_called()
        self.assertEqual({}, metrics.probe_seconds)

    async def test_subscriber_routes_sys_messages_to_metrics_and_not_to_the_ingest_pipeline(self) -> None:
        metrics = BrokerMetrics()
        ingestor = SimpleNamespace(record_invalid_topic=AsyncMock())
        subscriber = MqttSubscriber(
            heartbeat_pipeline=[],
            telemetry_ingestor=ingestor,
            gateway_handler=SimpleNamespace(),
            realtime_hub=SimpleNamespace(),
            presence_hub=SimpleNamespace(),
            broker_metrics=metrics,
        )

        result = await subscriber.on_message(MagicMock(), SYS_STORED_TOPIC, b'12', 0, None)

        self.assertEqual(0, result)
        self.assertEqual(12, metrics.stored_messages)
        ingestor.record_invalid_topic.assert_not_awaited()


if __name__ == '__main__':
    unittest.main()
