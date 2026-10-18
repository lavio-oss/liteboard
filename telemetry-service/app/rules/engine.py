from __future__ import annotations

import math
from dataclasses import dataclass, replace
from datetime import datetime
from typing import Any, Literal

from app.rules.definition import RuleRevision

Action = Literal['none', 'open', 'seen', 'clear']


@dataclass(frozen=True)
class EvaluationState:
    revision_id: str | None = None
    trigger_since: datetime | None = None
    clear_since: datetime | None = None
    cursor_received_at: datetime | None = None
    cursor_sample_id: str | None = None


@dataclass(frozen=True)
class EvaluatedSample:
    id: str
    message_id: str
    received_at: datetime
    values: dict[str, Any]


@dataclass(frozen=True)
class Decision:
    state: EvaluationState
    action: Action
    severity_changed: bool
    value: float | None
    stale: bool = False


def evaluate(
    state: EvaluationState,
    rule: RuleRevision,
    active_alert_severity: str | None,
    sample: EvaluatedSample,
) -> Decision:
    """Advance one Device+rule state by one sample ordered by server received_at."""
    if _is_stale(state, sample):
        return Decision(state=state, action='none', severity_changed=False, value=None, stale=True)

    trigger_since = state.trigger_since
    clear_since = state.clear_since
    if state.revision_id != rule.revision_id:
        trigger_since = clear_since = None
    if state.cursor_received_at is not None and sample.received_at - state.cursor_received_at > rule.max_sample_gap:
        trigger_since = clear_since = None

    alert_active = active_alert_severity is not None
    severity_changed = alert_active and active_alert_severity != rule.severity
    value = _numeric(sample.values.get(rule.field))
    action: Action = 'none'

    if value is None:
        trigger_since = clear_since = None
    elif not alert_active:
        clear_since = None
        if rule.trigger.matches(value):
            trigger_since = trigger_since or sample.received_at
            if sample.received_at - trigger_since >= rule.trigger.duration:
                action = 'open'
                trigger_since = None
        else:
            trigger_since = None
    else:
        trigger_since = None
        if rule.trigger.matches(value):
            action = 'seen'
        if rule.clear.matches(value):
            clear_since = clear_since or sample.received_at
            if sample.received_at - clear_since >= rule.clear.duration:
                action = 'clear'
                clear_since = None
        else:
            clear_since = None

    next_state = replace(
        state,
        revision_id=rule.revision_id,
        trigger_since=trigger_since,
        clear_since=clear_since,
        cursor_received_at=sample.received_at,
        cursor_sample_id=sample.id,
    )

    return Decision(state=next_state, action=action, severity_changed=severity_changed, value=value)


def _is_stale(state: EvaluationState, sample: EvaluatedSample) -> bool:
    if state.cursor_received_at is None:
        return False
    if state.cursor_sample_id is None:
        return sample.received_at <= state.cursor_received_at

    return (sample.received_at, sample.id) <= (state.cursor_received_at, state.cursor_sample_id)


def _numeric(raw: Any) -> float | None:
    if isinstance(raw, bool) or not isinstance(raw, (int, float)) or not math.isfinite(raw):
        return None

    return float(raw)
