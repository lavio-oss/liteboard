from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any, Literal
from uuid import uuid4

from asyncpg import Connection

from app.storage.queries import INSERT_ALERT_EVENT_SQL, INSERT_OUTBOX_EVENT_SQL, SELECT_ALERT_SNAPSHOT_SQL

AlertEventType = Literal['opened', 'severity_changed', 'cleared']


def iso(value: datetime | None) -> str | None:
    """Matches Laravel's Carbon::toISOString() so both producers emit the same timestamps."""
    if value is None:
        return None
    return value.astimezone(timezone.utc).isoformat(timespec='microseconds').replace('+00:00', 'Z')


def alert_payload(snapshot: Any, *, event_id: str, event_type: str, occurred_at: datetime) -> dict[str, Any]:
    return {
        'id': event_id,
        'type': event_type,
        'occurred_at': iso(occurred_at),
        'tenant_id': str(snapshot['tenant_id']),
        'project_id': str(snapshot['project_id']),
        'alert': {
            'id': str(snapshot['id']),
            'rule_id': str(snapshot['alert_rule_id']),
            'rule_code': snapshot['rule_code'],
            'rule_revision': snapshot['rule_revision'],
            'severity': snapshot['severity'],
            'device_id': str(snapshot['device_id']),
            'equipment_asset_id': None if snapshot['equipment_asset_id'] is None else str(snapshot['equipment_asset_id']),
            'opened_at': iso(snapshot['opened_at']),
            'cleared_at': iso(snapshot['cleared_at']),
            'clear_reason': snapshot['clear_reason'],
            'acknowledged_at': iso(snapshot['acknowledged_at']),
        },
    }


async def record_alert_transition(
    connection: Connection,
    *,
    alert_id: str,
    project_id: str,
    event_type: AlertEventType,
    occurred_at: datetime,
    revision_id: str,
    sample_id: str | None = None,
    message_id: str | None = None,
    data: dict[str, Any] | None = None,
    idempotency_suffix: str = '',
) -> None:
    """Writes the alert history row and its outbox event inside the caller's transaction."""
    await connection.execute(
        INSERT_ALERT_EVENT_SQL,
        str(uuid4()),
        project_id,
        alert_id,
        event_type,
        occurred_at,
        revision_id,
        sample_id,
        message_id,
        json.dumps(data or {}),
    )
    snapshot = await connection.fetchrow(SELECT_ALERT_SNAPSHOT_SQL, alert_id)
    if snapshot is None:
        raise RuntimeError('The alert written in this transaction could not be read back.')
    event_id = str(uuid4())
    outbox_type = f'alert.{event_type}'
    await connection.execute(
        INSERT_OUTBOX_EVENT_SQL,
        event_id,
        project_id,
        outbox_type,
        alert_id,
        f'alert:{alert_id}:{event_type}{idempotency_suffix}',
        json.dumps(alert_payload(snapshot, event_id=event_id, event_type=outbox_type, occurred_at=occurred_at)),
    )
