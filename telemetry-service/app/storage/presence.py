from __future__ import annotations

from asyncpg.pool import Pool


UPDATE_DEVICE_PRESENCE_SQL = """
UPDATE devices d
SET connection_state = 'online',
    last_seen_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
FROM projects p
JOIN tenants t ON t.id = p.tenant_id
WHERE d.id = $1::uuid
  AND d.project_id = $2::uuid
  AND p.id = d.project_id
  AND d.status = 'active'
  AND d.approval_status = 'approved'
  AND p.status = 'active'
  AND t.status = 'active'
"""


class PostgresPresenceWriter:
    def __init__(self, db_pool: Pool) -> None:
        self._db_pool = db_pool

    async def mark_online(self, *, device_id: str, project_id: str) -> None:
        async with self._db_pool.acquire() as connection:
            await connection.execute(UPDATE_DEVICE_PRESENCE_SQL, device_id, project_id)
