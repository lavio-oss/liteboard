from __future__ import annotations

import json
import logging
from datetime import datetime
from typing import Any, Literal, Protocol
from uuid import uuid4

from asyncpg.pool import Pool

from app.ingress.sub_device_registry import SubDeviceRejected
from app.realtime.presence_hub import PresenceHub
from app.storage.presence import device_presence_from_row
from app.storage.queries import (
    INSERT_UNRESOLVED_MESSAGE_SQL,
    MARK_GATEWAY_PRESENCE_SQL,
    SELECT_DEVICE_BY_CODE_SQL,
    UPDATE_DEVICE_PRESENCE_SQL,
)
from app.storage.telemetry_ingest import IngestResult

GatewayAction = Literal['connect', 'telemetry', 'disconnect']
GATEWAY_ACTIONS: frozenset[str] = frozenset({'connect', 'telemetry', 'disconnect'})
MAX_SUB_DEVICE_CODE_LENGTH = 64
LOGGER = logging.getLogger(__name__)


class Registry(Protocol):
    async def register(self, *, gateway_id: str, code: str) -> str: ...


class Ingestor(Protocol):
    async def ingest(
        self, *, project_id: str, device_id: str, raw: bytes, retained: bool, received_wall: datetime,
    ) -> IngestResult: ...


def parse_gateway_payload(action: GatewayAction, raw: bytes) -> dict[str, list[dict[str, Any]]] | None:
    """Returns sub-device code -> telemetry items (empty for connect/disconnect), or None when malformed."""
    try:
        decoded = json.loads(raw.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return None

    if not isinstance(decoded, dict):
        return None

    if action != 'telemetry':
        code = decoded.get('device')
        return {code: []} if _is_code(code) else None

    if not decoded:
        return None
    for code, items in decoded.items():
        if not _is_code(code) or not isinstance(items, list) or not items:
            return None
        if not all(isinstance(item, dict) for item in items):
            return None
    return decoded


def _is_code(value: object) -> bool:
    return isinstance(value, str) and 0 < len(value) <= MAX_SUB_DEVICE_CODE_LENGTH


class GatewayHandler:
    """Fans one gateway message out to its sub-devices; each telemetry item is ingested like a direct message."""

    def __init__(self, *, db_pool: Pool, telemetry_ingestor: Ingestor, registry: Registry, presence_hub: PresenceHub) -> None:
        self._db_pool = db_pool
        self._telemetry_ingestor = telemetry_ingestor
        self._registry = registry
        self._presence_hub = presence_hub

    async def handle(
        self,
        *,
        project_id: str,
        gateway_id: str,
        action: GatewayAction,
        topic: str,
        raw: bytes,
        retained: bool,
        received_wall: datetime,
    ) -> list[IngestResult]:
        gateway_row = await self._db_pool.fetchrow(MARK_GATEWAY_PRESENCE_SQL, gateway_id, project_id)
        if gateway_row is None:
            await self._record_unresolved(topic, 'gateway_not_found', raw, received_wall)
            return []
        self._presence_hub.publish_device_presence(device_presence_from_row(gateway_row))

        batches = parse_gateway_payload(action, raw)
        if batches is None:
            await self._record_unresolved(topic, 'gateway_payload_invalid', raw, received_wall)
            return []

        results: list[IngestResult] = []
        for code, items in batches.items():
            sub_device_id = await self._resolve(project_id, gateway_id, code, topic, raw, received_wall)
            if sub_device_id is None:
                continue

            if action == 'connect':
                row = await self._db_pool.fetchrow(UPDATE_DEVICE_PRESENCE_SQL, sub_device_id, project_id)
                if row is not None:
                    self._presence_hub.publish_device_presence(device_presence_from_row(row))
            # shortcut: disconnect does not force the sub-device offline because the presence monitor derives state
            # from last_seen_at and would flip it back; it goes offline after 90 s. Add an explicit offline marker if needed.

            for item in items:
                results.append(await self._telemetry_ingestor.ingest(
                    project_id=project_id,
                    device_id=sub_device_id,
                    raw=json.dumps(item).encode(),
                    retained=retained,
                    received_wall=received_wall,
                ))
        return results

    async def _resolve(
        self, project_id: str, gateway_id: str, code: str, topic: str, raw: bytes, received_wall: datetime,
    ) -> str | None:
        row = await self._db_pool.fetchrow(SELECT_DEVICE_BY_CODE_SQL, project_id, code)
        if row is not None:
            if row['gateway_device_id'] is not None and str(row['gateway_device_id']) == gateway_id:
                return str(row['id'])
            # The code belongs to a direct device or another gateway: never write into its data.
            await self._record_unresolved(topic, 'gateway_mismatch', raw, received_wall)
            return None

        try:
            return await self._registry.register(gateway_id=gateway_id, code=code)
        except SubDeviceRejected as rejection:
            LOGGER.warning('gateway_sub_device_rejected', extra={'gateway_id': gateway_id, 'code': rejection.code})
            await self._record_unresolved(topic, 'sub_device_rejected', raw, received_wall)
            return None

    async def _record_unresolved(self, topic: str, reason: str, raw: bytes, received_wall: datetime) -> None:
        stages = [{'stage': 'received', 'at': received_wall.isoformat()}]
        await self._db_pool.execute(
            INSERT_UNRESOLVED_MESSAGE_SQL, str(uuid4()), topic[:255], reason, len(raw), json.dumps(stages),
        )
