from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Literal

from asyncpg.pool import Pool

from app.storage.queries import UPDATE_DEVICE_PRESENCE_SQL

ConnectionState = Literal['online', 'unstable', 'offline']


@dataclass(frozen=True)
class DevicePresence:
    tenant_id: str
    project_id: str
    device_id: str
    connection_state: ConnectionState
    last_seen_at: datetime | None
    updated_at: datetime


def device_presence_from_row(row: Any) -> DevicePresence:
    return DevicePresence(
        tenant_id=str(row['tenant_id']),
        project_id=str(row['project_id']),
        device_id=str(row['device_id']),
        connection_state=row['connection_state'],
        last_seen_at=row['last_seen_at'],
        updated_at=row['updated_at'],
    )


class DBPresenceWriter(ABC):
    @abstractmethod
    async def mark_online(self, *, device_id: str, project_id: str) -> DevicePresence | None:
        ...


class PostgresPresenceWriter(DBPresenceWriter):
    def __init__(self, db_pool: Pool) -> None:
        self._db_pool = db_pool

    async def mark_online(self, *, device_id: str, project_id: str) -> DevicePresence | None:
        async with self._db_pool.acquire() as connection:
            row = await connection.fetchrow(UPDATE_DEVICE_PRESENCE_SQL, device_id, project_id)

        return None if row is None else device_presence_from_row(row)
