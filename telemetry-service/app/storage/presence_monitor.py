from __future__ import annotations

import asyncio
import logging
from datetime import timedelta
from typing import Any, Literal

from asyncpg import Connection
from asyncpg.pool import Pool

from app.realtime.presence_hub import PresenceHub
from app.storage.alerting import record_alert_transition
from app.storage.presence import device_presence_from_row
from app.storage.queries import (
    CLEAR_OFFLINE_ALERTS_SQL,
    RESET_OFFLINE_EVALUATION_STATES_SQL,
    UPDATE_STALE_DEVICE_PRESENCE_SQL,
)

POLL_INTERVAL_SECONDS = 5
LOGGER = logging.getLogger(__name__)
ConnectionState = Literal['online', 'unstable', 'offline']


def connection_state_for_age(age: timedelta | None) -> ConnectionState:
    if age is None:
        return 'offline'
    if age <= timedelta(seconds=30):
        return 'online'
    if age <= timedelta(seconds=90):
        return 'unstable'
    return 'offline'


class PostgresDevicePresenceMonitor:
    def __init__(self, db_pool: Pool, presence_hub: PresenceHub) -> None:
        self._db_pool = db_pool
        self._presence_hub = presence_hub

    async def refresh(self) -> int:
        async with self._db_pool.acquire() as connection:
            async with connection.transaction():
                rows = await connection.fetch(UPDATE_STALE_DEVICE_PRESENCE_SQL)
                offline_device_ids = [row['device_id'] for row in rows if row['connection_state'] == 'offline']
                if offline_device_ids:
                    await self._clear_offline_alerts(connection, offline_device_ids)

        for row in rows:
            self._presence_hub.publish_device_presence(device_presence_from_row(row))

        return len(rows)

    @staticmethod
    async def _clear_offline_alerts(connection: Connection, device_ids: list[Any]) -> None:
        # Alerts follow device presence: offline clears them and restarts rule windows after reconnect.
        await connection.execute(RESET_OFFLINE_EVALUATION_STATES_SQL, device_ids)
        for alert in await connection.fetch(CLEAR_OFFLINE_ALERTS_SQL, device_ids):
            await record_alert_transition(
                connection,
                alert_id=str(alert['id']),
                project_id=str(alert['project_id']),
                event_type='cleared',
                occurred_at=alert['cleared_at'],
                revision_id=str(alert['alert_rule_revision_id']),
                data={'clear_reason': 'device_offline'},
            )

    async def run(self) -> None:
        while True:
            await asyncio.sleep(POLL_INTERVAL_SECONDS)
            try:
                await self.refresh()
            except Exception:
                LOGGER.exception('device_presence_refresh_failed')
