from __future__ import annotations

import asyncio
import logging
from datetime import timedelta
from typing import Literal

from asyncpg.pool import Pool

from app.realtime.presence_hub import PresenceHub
from app.storage.presence import device_presence_from_row
from app.storage.queries import UPDATE_STALE_DEVICE_PRESENCE_SQL

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

        for row in rows:
            self._presence_hub.publish_device_presence(device_presence_from_row(row))

        return len(rows)

    async def run(self) -> None:
        while True:
            await asyncio.sleep(POLL_INTERVAL_SECONDS)
            try:
                await self.refresh()
            except Exception:
                LOGGER.exception('device_presence_refresh_failed')
