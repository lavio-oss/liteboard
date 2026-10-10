from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class DeviceSchema:
    version: int
    definition: dict[str, Any]
