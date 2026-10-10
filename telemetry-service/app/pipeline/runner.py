from __future__ import annotations

from collections.abc import Awaitable, Callable, Sequence

from app.pipeline.context import MessageContext

Stage = Callable[[MessageContext], Awaitable[None]]


async def run_pipeline(stages: Sequence[Stage], ctx: MessageContext) -> None:
    for stage in stages:
        await stage(ctx)
        if ctx.reasons:
            break

    print(
        {
            'project_id': ctx.project_id,
            'device_id': ctx.device_id,
            'channel': ctx.channel,
            'payload_size': len(ctx.raw),
            'outcome': 'rejected' if ctx.reasons else 'accepted',
            'reasons': [{'code': reason.code, 'field': reason.field} for reason in ctx.reasons],
        },
        flush=True,
    )
