from __future__ import annotations

import asyncio
from datetime import datetime, timezone
from typing import Any

from app.storage.presence import DevicePresence

DEFAULT_QUEUE_SIZE = 256
DEFAULT_MAX_CONNECTIONS = 500


def _iso(value: datetime) -> str:
    return value.astimezone(timezone.utc).isoformat().replace('+00:00', 'Z')


def device_presence_event(presence: DevicePresence) -> dict[str, Any]:
    return {
        'type': 'device.presence',
        'data': {
            'project_id': presence.project_id,
            'device_id': presence.device_id,
            'connection_state': presence.connection_state,
            'last_seen_at': None if presence.last_seen_at is None else _iso(presence.last_seen_at),
            'updated_at': _iso(presence.updated_at),
        },
    }


class PresenceSubscriber:
    def __init__(self, tenant_id: str, queue_size: int) -> None:
        self.tenant_id = tenant_id
        self.queue: asyncio.Queue[dict[str, Any]] = asyncio.Queue(maxsize=queue_size)
        self.overflowed = asyncio.Event()


class PresenceHub:
    """Tenant-scoped fan-out with a bounded queue per subscriber."""

    def __init__(self, *, max_connections: int = DEFAULT_MAX_CONNECTIONS, queue_size: int = DEFAULT_QUEUE_SIZE) -> None:
        self._max_connections = max_connections
        self._queue_size = queue_size
        self._subscribers: dict[str, set[PresenceSubscriber]] = {}
        self._count = 0

    @property
    def connection_count(self) -> int:
        return self._count

    def subscribe(self, tenant_id: str) -> PresenceSubscriber | None:
        if self._count >= self._max_connections:
            return None
        subscriber = PresenceSubscriber(tenant_id, self._queue_size)
        self._subscribers.setdefault(tenant_id, set()).add(subscriber)
        self._count += 1
        return subscriber

    def unsubscribe(self, subscriber: PresenceSubscriber) -> None:
        subscribers = self._subscribers.get(subscriber.tenant_id)
        if subscribers is None or subscriber not in subscribers:
            return
        subscribers.discard(subscriber)
        self._count -= 1
        if not subscribers:
            del self._subscribers[subscriber.tenant_id]

    def publish(self, tenant_id: str, event: dict[str, Any]) -> int:
        delivered = 0
        for subscriber in list(self._subscribers.get(tenant_id, ())):
            try:
                subscriber.queue.put_nowait(event)
                delivered += 1
            except asyncio.QueueFull:
                subscriber.overflowed.set()
                self.unsubscribe(subscriber)
        return delivered

    def publish_device_presence(self, presence: DevicePresence) -> int:
        return self.publish(presence.tenant_id, device_presence_event(presence))


