from __future__ import annotations

import logging
import os
import re
import time
from typing import Any

LOGGER = logging.getLogger(__name__)

SYS_DROPPED_TOPIC = '$SYS/broker/publish/messages/dropped'
SYS_STORED_TOPIC = '$SYS/broker/store/messages/count'
SYS_TOPICS = (SYS_DROPPED_TOPIC, SYS_STORED_TOPIC)

# Same lookups as the go-auth queries in infrastructure/mosquitto/mosquitto.conf, minus the sha256 of the token.
PROBE_QUERIES = {
    'auth': "SELECT mqtt_password_hash FROM v_mqtt_users WHERE token_hash = '{h}'::char(64) LIMIT 1",
    'acl': "SELECT topic FROM v_mqtt_acls WHERE token_hash = '{h}'::char(64) AND rw = 2",
}
HEX_SHA256 = re.compile(r'[0-9a-f]{64}')


class BrokerMetrics:
    """Broker drop counter and queue size from $SYS, plus a timed auth/ACL query against PostgreSQL."""

    def __init__(self) -> None:
        # Mosquitto's max_queued_messages is 1000; alert well before that.
        self.queue_alert_threshold = int(os.getenv('BROKER_QUEUE_ALERT_THRESHOLD', '500'))
        # A drop stays "active" this long after the last increase, so a scrape never misses it.
        self.drop_alert_seconds = float(os.getenv('BROKER_DROP_ALERT_SECONDS', '300'))
        self.dropped_total: int | None = None
        self.stored_messages: int | None = None
        self.probe_seconds: dict[str, float] = {}
        self._last_drop_at: float | None = None

    def observe_sys(self, topic: str, payload: bytes) -> None:
        try:
            value = int(payload)
        except ValueError:
            return

        if topic == SYS_STORED_TOPIC:
            self.stored_messages = value
            if value > self.queue_alert_threshold:
                LOGGER.error('broker_queue_depth_high', extra={'stored_messages': value, 'threshold': self.queue_alert_threshold})
        elif topic == SYS_DROPPED_TOPIC:
            # The first sample is a baseline: a restart of this service must not look like a new drop.
            if self.dropped_total is not None and value > self.dropped_total:
                self._last_drop_at = time.monotonic()
                LOGGER.error('broker_messages_dropped', extra={'new_drops': value - self.dropped_total, 'dropped_total': value})
            self.dropped_total = value

    async def probe(self, db_pool: Any) -> None:
        token_hash = await db_pool.fetchval('SELECT token_hash FROM v_mqtt_users LIMIT 1')
        if token_hash is None or not HEX_SHA256.fullmatch(token_hash):
            return

        async with db_pool.acquire() as connection:
            for name, query in PROBE_QUERIES.items():
                started = time.perf_counter()
                # No arguments means the simple protocol, so it is planned on every call, as go-auth does.
                await connection.execute(query.format(h=token_hash))
                self.probe_seconds[name] = time.perf_counter() - started

    def alerts(self) -> dict[str, bool]:
        return {
            'messages_dropped': self._last_drop_at is not None and time.monotonic() - self._last_drop_at < self.drop_alert_seconds,
            'queue_depth_high': self.stored_messages is not None and self.stored_messages > self.queue_alert_threshold,
        }

    def render(self) -> str:
        lines = ['# TYPE liteboard_broker_publish_dropped_total counter']
        if self.dropped_total is not None:
            lines.append(f'liteboard_broker_publish_dropped_total {self.dropped_total}')
        lines.append('# TYPE liteboard_broker_store_messages gauge')
        if self.stored_messages is not None:
            lines.append(f'liteboard_broker_store_messages {self.stored_messages}')
        lines.append('# TYPE liteboard_auth_probe_seconds gauge')
        lines += [f'liteboard_auth_probe_seconds{{query="{name}"}} {seconds:.6f}' for name, seconds in self.probe_seconds.items()]
        lines.append('# TYPE liteboard_ingest_alert gauge')
        lines += [f'liteboard_ingest_alert{{reason="{reason}"}} {int(active)}' for reason, active in self.alerts().items()]

        return '\n'.join(lines) + '\n'
