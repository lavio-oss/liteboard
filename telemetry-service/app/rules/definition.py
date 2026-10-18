from __future__ import annotations

import json
import math
from dataclasses import dataclass
from datetime import timedelta
from typing import Any, Literal

Operator = Literal['gt', 'gte', 'lt', 'lte']
OPERATORS: frozenset[str] = frozenset({'gt', 'gte', 'lt', 'lte'})


class InvalidRuleDefinition(ValueError):
    pass


@dataclass(frozen=True)
class Condition:
    operator: Operator
    value: float
    duration: timedelta

    def matches(self, value: float) -> bool:
        if self.operator == 'gt':
            return value > self.value
        if self.operator == 'gte':
            return value >= self.value
        if self.operator == 'lt':
            return value < self.value
        return value <= self.value


@dataclass(frozen=True)
class RuleRevision:
    rule_id: str
    rule_code: str
    revision_id: str
    revision: int
    field: str
    trigger: Condition
    clear: Condition
    max_sample_gap: timedelta
    severity: str


def parse_rule_revision(
    *, rule_id: str, rule_code: str, revision_id: str, revision: int, definition: Any,
) -> RuleRevision:
    # Laravel validates on write; this guards the worker against rows edited outside the API.
    raw = json.loads(definition) if isinstance(definition, str) else definition
    if not isinstance(raw, dict):
        raise InvalidRuleDefinition('definition must be an object')

    field = raw.get('field')
    severity = raw.get('severity')
    if not isinstance(field, str) or field == '':
        raise InvalidRuleDefinition('field must be a non-empty string')
    if not isinstance(severity, str) or severity == '':
        raise InvalidRuleDefinition('severity must be a non-empty string')

    return RuleRevision(
        rule_id=str(rule_id),
        rule_code=rule_code,
        revision_id=str(revision_id),
        revision=revision,
        field=field,
        trigger=_condition(raw.get('trigger'), 'trigger'),
        clear=_condition(raw.get('clear'), 'clear'),
        max_sample_gap=_seconds(raw.get('max_sample_gap_seconds'), 'max_sample_gap_seconds'),
        severity=severity,
    )


def _condition(raw: Any, name: str) -> Condition:
    if not isinstance(raw, dict):
        raise InvalidRuleDefinition(f'{name} must be an object')
    operator = raw.get('operator')
    value = raw.get('value')
    if operator not in OPERATORS:
        raise InvalidRuleDefinition(f'{name}.operator is not supported')
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value):
        raise InvalidRuleDefinition(f'{name}.value must be a finite number')

    return Condition(operator=operator, value=float(value), duration=_seconds(raw.get('duration_seconds'), f'{name}.duration_seconds'))


def _seconds(raw: Any, name: str) -> timedelta:
    if isinstance(raw, bool) or not isinstance(raw, int) or raw <= 0:
        raise InvalidRuleDefinition(f'{name} must be a positive integer')

    return timedelta(seconds=raw)
