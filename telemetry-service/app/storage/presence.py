from __future__ import annotations

from abc import ABC, abstractmethod

from asyncpg.pool import Pool

from app.storage.queries import UPDATE_DEVICE_PRESENCE_SQL


class DBPresenceWriter(ABC):
    @abstractmethod
    async def mark_online(self, *, device_id: str, project_id: str) -> None:
        ...


class PostgresPresenceWriter(DBPresenceWriter):
    def __init__(self, db_pool: Pool) -> None:
        self._db_pool = db_pool

    async def mark_online(self, *, device_id: str, project_id: str) -> None:
        async with self._db_pool.acquire() as connection:
            await connection.execute(UPDATE_DEVICE_PRESENCE_SQL, device_id, project_id)
