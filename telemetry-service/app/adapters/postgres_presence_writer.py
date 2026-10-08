from __future__ import annotations

from asyncpg.pool import Pool


UPDATE_DEVICE_PRESENCE_SQL = """
UPDATE devices
SET connection_state = 'online',
    last_seen_at = NOW(),
    updated_at = NOW()
WHERE id = $1::uuid
  AND project_id = $2::uuid
  AND status = 'active'
"""


class PostgresPresenceWriter:
    def __init__(self, db_pool: Pool) -> None:
        self._db_pool = db_pool

    async def mark_online(self, *, device_id: str, project_id: str) -> None:
        async with self._db_pool.acquire() as connection:
            await connection.execute(UPDATE_DEVICE_PRESENCE_SQL, device_id, project_id)
