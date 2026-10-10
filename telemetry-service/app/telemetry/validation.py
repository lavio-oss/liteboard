from __future__ import annotations

import math
from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class Rejection:
    code: str
    field: str | None = None


@dataclass(frozen=True)
class ValidationResult:
    reasons: tuple[Rejection, ...]

    @property
    def accepted(self) -> bool:
        return not self.reasons


def validate_telemetry(*, definition: dict[str, Any], version: int, payload: dict[str, Any]) -> ValidationResult:
    if 'schema_version' in payload:
        schema_version = payload['schema_version']
        if isinstance(schema_version, bool) or schema_version != version:
            return ValidationResult((Rejection('schema_version_mismatch'),))

    values = payload.get('values')
    if not isinstance(values, dict):
        return ValidationResult((Rejection('invalid_payload'),))

    fields: dict[str, dict[str, Any]] = definition['fields']
    reasons: list[Rejection] = []

    for name in sorted(fields.keys() | values.keys()):
        spec = fields.get(name)
        if spec is None:
            if definition['strict_mode']:
                reasons.append(Rejection('unknown_field', name))
            continue

        if name not in values:
            if spec['required']:
                reasons.append(Rejection('missing_required_field', name))
            continue

        value = values[name]
        if not _matches_type(value, spec['type']):
            reasons.append(Rejection('invalid_type', name))
        elif 'min' in spec and value < spec['min']:
            reasons.append(Rejection('below_minimum', name))
        elif 'max' in spec and value > spec['max']:
            reasons.append(Rejection('above_maximum', name))
        elif 'allowed_values' in spec and value not in spec['allowed_values']:
            reasons.append(Rejection('value_not_allowed', name))

    return ValidationResult(tuple(reasons))


def _matches_type(value: Any, field_type: str) -> bool:
    if isinstance(value, bool):
        return field_type == 'boolean'

    if field_type == 'integer':
        return isinstance(value, int)

    if field_type == 'float':
        return isinstance(value, (int, float)) and math.isfinite(value)

    if field_type == 'string':
        return isinstance(value, str)

    return False


@dataclass(frozen=True)
class DeviceSchema:
    version: int
    definition: dict[str, Any]
