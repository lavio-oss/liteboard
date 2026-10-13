from __future__ import annotations

import json
from typing import TYPE_CHECKING

from app.pipeline.context import MessageContext
from app.telemetry.validation import Rejection

if TYPE_CHECKING:
    from app.storage.presence import PostgresPresenceWriter


async def decode_json(ctx: MessageContext) -> None:
    try:
        decoded = json.loads(ctx.raw.decode('utf-8')) if ctx.raw else None
    except (UnicodeDecodeError, json.JSONDecodeError):
        decoded = None

    if isinstance(decoded, dict):
        ctx.payload = decoded
    else:
        ctx.reject(Rejection('invalid_json'))


class MarkPresence:
    def __init__(self, presence_writer: PostgresPresenceWriter) -> None:
        self._presence_writer = presence_writer

    async def __call__(self, ctx: MessageContext) -> None:
        await self._presence_writer.mark_online(device_id=ctx.device_id, project_id=ctx.project_id)
