from __future__ import annotations

import base64
import binascii
import hashlib
import hmac
import json
from dataclasses import dataclass
from uuid import UUID

PRESENCE_SCOPE = 'device-presence'


@dataclass(frozen=True)
class PresenceTicketClaims:
    user_id: str
    tenant_id: str
    expires_at: int


def _decode(segment: str) -> bytes:
    return base64.urlsafe_b64decode(segment + '=' * (-len(segment) % 4))


def verify_presence_ticket(ticket: str, *, secret: bytes, now: float) -> PresenceTicketClaims | None:
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
    user_id, tenant_id, scope, expires_at = (
        claims.get('sub'), claims.get('tenant_id'), claims.get('scope'), claims.get('exp'),
    )
    if not (
        isinstance(user_id, str)
        and isinstance(tenant_id, str)
        and scope == PRESENCE_SCOPE
        and isinstance(expires_at, int)
        and not isinstance(expires_at, bool)
        and expires_at > now
    ):
        return None
    try:
        UUID(user_id)
        UUID(tenant_id)
    except ValueError:
        return None

    return PresenceTicketClaims(user_id, tenant_id, expires_at)
