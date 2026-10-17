from __future__ import annotations

import json

from app.pipeline.context import MessageContext
from app.realtime.presence_hub import PresenceHub
from app.storage.presence import DBPresenceWriter
from app.telemetry.validation import Rejection


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
    def __init__(self, presence_writer: DBPresenceWriter, presence_hub: PresenceHub) -> None:
        self._presence_writer = presence_writer
        self._presence_hub = presence_hub

    async def __call__(self, ctx: MessageContext) -> None:
        presence = await self._presence_writer.mark_online(device_id=ctx.device_id, project_id=ctx.project_id)

        if presence is not None:
            self._presence_hub.publish_device_presence(presence)
