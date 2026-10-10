from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from app.telemetry.validation import Rejection


@dataclass
class MessageContext:
    project_id: str
    device_id: str
    channel: str
    raw: bytes
    payload: dict[str, Any] | None = None
    reasons: list[Rejection] = field(default_factory=list)

    def reject(self, *reasons: Rejection) -> None:
        self.reasons.extend(reasons)
