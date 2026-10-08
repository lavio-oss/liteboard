from __future__ import annotations

from typing import Protocol


class PresenceWriter(Protocol):
    async def mark_online(self, *, device_id: str, project_id: str) -> None: ...
