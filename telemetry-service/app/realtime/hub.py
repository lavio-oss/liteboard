from __future__ import annotations

import asyncio
from datetime import datetime, timezone
from typing import Any

from app.storage.telemetry_ingest import PersistedSample, RecordedOutcome

DEFAULT_QUEUE_SIZE = 256
DEFAULT_MAX_CONNECTIONS = 500


def _iso(value: datetime) -> str:
    return value.astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.%fZ')


def sample_event(sample: PersistedSample) -> dict[str, Any]:
    """Same shape as the REST TelemetrySampleResource so clients deduplicate by `id`."""
    return {
        'type': 'telemetry.sample',
        'data': {
            'id': sample.id,
            'project_id': sample.project_id,
            'device_id': sample.device_id,
            'device_profile_version_id': sample.device_profile_version_id,
            'message_id': sample.message_id,
            'occurred_at': _iso(sample.occurred_at),
            'received_at': _iso(sample.received_at),
            'values': sample.values,
        },
    }


def outcome_event(outcome: RecordedOutcome) -> dict[str, Any]:
    """Same shape as the REST TelemetryIngestOutcomeResource for reconnect deduplication."""
    return {
        'type': 'telemetry.outcome',
        'data': {
            'id': outcome.id,
            'project_id': outcome.project_id,
            'device_id': outcome.device_id,
            'processing_id': outcome.processing_id,
            'message_id': outcome.message_id,
            'status': outcome.status,
            'reason_codes': [
                {'code': reason.code, 'field': reason.field}
                for reason in outcome.reason_codes
            ],
            'stages': [dict(stage) for stage in outcome.stages],
            'received_at': _iso(outcome.received_at),
        },
    }


class Subscriber:
    def __init__(self, project_id: str, device_id: str, queue_size: int) -> None:
        self.project_id = project_id
        self.device_id = device_id
        self.queue: asyncio.Queue[dict[str, Any]] = asyncio.Queue(maxsize=queue_size)
        self.overflowed = asyncio.Event()


class RealtimeHub:
    """In-process fan-out with a bounded queue per subscriber; a full queue drops the subscriber."""

    def __init__(self, *, max_connections: int = DEFAULT_MAX_CONNECTIONS, queue_size: int = DEFAULT_QUEUE_SIZE) -> None:
        self._max_connections = max_connections
        self._queue_size = queue_size
        self._subscribers: dict[tuple[str, str], set[Subscriber]] = {}
        self._count = 0

    @property
    def connection_count(self) -> int:
        return self._count

    def subscribe(self, project_id: str, device_id: str) -> Subscriber | None:
        if self._count >= self._max_connections:
            return None
        subscriber = Subscriber(project_id, device_id, self._queue_size)
        self._subscribers.setdefault((project_id, device_id), set()).add(subscriber)
        self._count += 1
        return subscriber

    def unsubscribe(self, subscriber: Subscriber) -> None:
        key = (subscriber.project_id, subscriber.device_id)
        subscribers = self._subscribers.get(key)
        if subscribers is None or subscriber not in subscribers:
            return
        subscribers.discard(subscriber)
        self._count -= 1
        if not subscribers:
            del self._subscribers[key]

    def publish(self, project_id: str, device_id: str, event: dict[str, Any]) -> int:
        delivered = 0
        for subscriber in list(self._subscribers.get((project_id, device_id), ())):
            try:
                subscriber.queue.put_nowait(event)
                delivered += 1
            except asyncio.QueueFull:
                subscriber.overflowed.set()
                self.unsubscribe(subscriber)
        return delivered
