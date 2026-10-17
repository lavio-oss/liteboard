from __future__ import annotations

import base64
import binascii
import hashlib
import hmac
import json
from dataclasses import dataclass
from uuid import UUID


@dataclass(frozen=True)
class TicketClaims:
    user_id: str
    project_id: str
    device_id: str
    expires_at: int


def _decode(segment: str) -> bytes:
    return base64.urlsafe_b64decode(segment + '=' * (-len(segment) % 4))


def verify_ticket(ticket: str, *, secret: bytes, now: float) -> TicketClaims | None:
    """Verifies a stream ticket signed by the Laravel control plane (TelemetryStreamTicketService)."""
    payload, separator, signature = ticket.partition('.')
    if not separator or not payload or not signature:
        return None

    expected = hmac.new(secret, payload.encode('ascii', 'replace'), hashlib.sha256).digest()
    try:
        if not hmac.compare_digest(_decode(signature), expected):
            return None
        claims = json.loads(_decode(payload))
    except (binascii.Error, ValueError):
        return None

    if not isinstance(claims, dict):
        return None
    user_id, project_id, device_id, expires_at = (
        claims.get('sub'), claims.get('project_id'), claims.get('device_id'), claims.get('exp'),
    )
    if not (isinstance(user_id, str) and isinstance(project_id, str) and isinstance(device_id, str)):
        return None
    if not isinstance(expires_at, int) or isinstance(expires_at, bool) or expires_at <= now:
        return None
    try:
        UUID(project_id)
        UUID(device_id)
    except ValueError:
        return None

    return TicketClaims(user_id, project_id, device_id, expires_at)
