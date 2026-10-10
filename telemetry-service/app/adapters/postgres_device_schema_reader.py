from __future__ import annotations

import json

from asyncpg.pool import Pool

from app.application.device_schema import DeviceSchema


SELECT_DEVICE_SCHEMA_SQL = """
SELECT dpv.version, dpv.definition
FROM devices d
JOIN device_profile_versions dpv
  ON dpv.id = d.device_profile_version_id
 AND dpv.project_id = d.project_id
WHERE d.id = $1::uuid
  AND d.project_id = $2::uuid
  AND d.status = 'active'
"""


class PostgresDeviceSchemaReader:
    def __init__(self, db_pool: Pool) -> None:
        self._db_pool = db_pool

    async def find_for_device(self, *, device_id: str, project_id: str) -> DeviceSchema | None:
        async with self._db_pool.acquire() as connection:
            row = await connection.fetchrow(SELECT_DEVICE_SCHEMA_SQL, device_id, project_id)

        if row is None:
            return None

        definition = row['definition']

        return DeviceSchema(
            version=row['version'],
            definition=json.loads(definition) if isinstance(definition, str) else definition,
        )
