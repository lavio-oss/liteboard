from __future__ import annotations

import asyncio
import logging
import time

from fastapi import WebSocket, WebSocketDisconnect

from app.realtime.presence_hub import PresenceHub, PresenceSubscriber
from app.realtime.presence_ticket import PresenceTicketClaims, verify_presence_ticket

CLOSE_INVALID_TICKET = 4401
CLOSE_ACCESS_EXPIRED = 4403
CLOSE_SLOW_CONSUMER = 4008
CLOSE_TRY_AGAIN_LATER = 1013
CLOSE_INTERNAL_ERROR = 1011
LOGGER = logging.getLogger(__name__)


async def device_presence_stream(websocket: WebSocket) -> None:
    """Streams status updates for one authorized tenant; renewals use freshly issued tickets."""
    secret: bytes | None = websocket.app.state.ws_secret
    hub: PresenceHub = websocket.app.state.presence_hub
    await websocket.accept()

    if secret is None:
        LOGGER.error('device_presence_ws_secret_missing')
        await websocket.close(code=CLOSE_INTERNAL_ERROR)
        return

    claims = verify_presence_ticket(websocket.query_params.get('ticket', ''), secret=secret, now=time.time())
    if claims is None:
        await websocket.close(code=CLOSE_INVALID_TICKET)
        return

    subscriber = hub.subscribe(claims.tenant_id)
    if subscriber is None:
        await websocket.close(code=CLOSE_TRY_AGAIN_LATER)
        return

    expires_at = [claims.expires_at]
    tasks = {
        asyncio.create_task(_send_events(websocket, subscriber)): None,
        asyncio.create_task(_receive_renewals(websocket, claims, secret, expires_at)): CLOSE_ACCESS_EXPIRED,
        asyncio.create_task(_watch_expiry(expires_at)): CLOSE_ACCESS_EXPIRED,
        asyncio.create_task(subscriber.overflowed.wait()): CLOSE_SLOW_CONSUMER,
    }
    try:
        done, pending = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
        for task in pending:
            task.cancel()
        finished = next(iter(done))
        close_code = tasks[finished] if finished.exception() is None else None
        if close_code is not None:
            await websocket.close(code=close_code)
    except WebSocketDisconnect:
        pass
    finally:
        hub.unsubscribe(subscriber)


async def _send_events(websocket: WebSocket, subscriber: PresenceSubscriber) -> None:
    while True:
        await websocket.send_json(await subscriber.queue.get())


async def _receive_renewals(
    websocket: WebSocket,
    claims: PresenceTicketClaims,
    secret: bytes,
    expires_at: list[int],
) -> None:
    while True:
        try:
            message = await websocket.receive_json()
        except ValueError:
            return
        if not isinstance(message, dict) or message.get('type') != 'renew' or not isinstance(message.get('ticket'), str):
            return
        renewed = verify_presence_ticket(message['ticket'], secret=secret, now=time.time())
        if renewed is None or (renewed.user_id, renewed.tenant_id) != (claims.user_id, claims.tenant_id):
            return
        expires_at[0] = renewed.expires_at


async def _watch_expiry(expires_at: list[int]) -> None:
    while (remaining := expires_at[0] - time.time()) > 0:
        await asyncio.sleep(remaining)
