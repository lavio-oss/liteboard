from __future__ import annotations

import hashlib
import json
import math
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from typing import Any
from uuid import uuid4

from app.telemetry.validation import Rejection

MAX_TELEMETRY_PAYLOAD_BYTES = 64 * 1024
MAX_MESSAGE_ID_LENGTH = 128
MAX_FUTURE_SKEW = timedelta(minutes=5)


@dataclass(frozen=True)
class TelemetryEnvelope:
    message_id: str
    schema_version: int | None
    occurred_at: datetime
    values: dict[str, Any]


@dataclass(frozen=True)
class EnvelopeInspection:
    payload: dict[str, Any] | None
    envelope: TelemetryEnvelope | None
    message_id: str | None
    content_hash: str | None
    reasons: tuple[Rejection, ...]


def inspect_telemetry_envelope(raw: bytes, *, received_at: datetime) -> EnvelopeInspection:
    if len(raw) > MAX_TELEMETRY_PAYLOAD_BYTES:
        return EnvelopeInspection(None, None, None, None, (Rejection('payload_too_large'),))

    decoded, content_hash = _decode_and_hash(raw)
    if decoded is None:
        return EnvelopeInspection(None, None, None, None, (Rejection('invalid_json'),))

    reasons: list[Rejection] = []

    raw_message_id = decoded.get('message_id')
    message_id: str | None = None
    if 'message_id' not in decoded:
        reasons.append(Rejection('missing_required_field', 'message_id'))
    elif not isinstance(raw_message_id, str) or not raw_message_id:
        reasons.append(Rejection('invalid_type', 'message_id'))
    elif len(raw_message_id) > MAX_MESSAGE_ID_LENGTH:
        reasons.append(Rejection('invalid_payload', 'message_id'))
    else:
        message_id = raw_message_id

    raw_schema_version = decoded.get('schema_version')
    schema_version: int | None = None
    if 'schema_version' not in decoded:
        reasons.append(Rejection('missing_required_field', 'schema_version'))
    elif isinstance(raw_schema_version, bool) or not isinstance(raw_schema_version, int):
        reasons.append(Rejection('invalid_type', 'schema_version'))
    elif raw_schema_version < 1:
        reasons.append(Rejection('invalid_payload', 'schema_version'))
    else:
        schema_version = raw_schema_version

    raw_occurred_at = decoded.get('occurred_at')
    occurred_at: datetime | None = None
    if 'occurred_at' not in decoded:
        reasons.append(Rejection('missing_required_field', 'occurred_at'))
    elif not isinstance(raw_occurred_at, str):
        reasons.append(Rejection('invalid_type', 'occurred_at'))
    else:
        occurred_at = _parse_occurred_at(raw_occurred_at)
        if occurred_at is None:
            reasons.append(Rejection('invalid_type', 'occurred_at'))
        elif occurred_at > received_at.astimezone(timezone.utc) + MAX_FUTURE_SKEW:
            reasons.append(Rejection('occurred_at_too_far_in_future', 'occurred_at'))

    raw_values = decoded.get('values')
    values: dict[str, Any] | None = None
    if 'values' not in decoded:
        reasons.append(Rejection('missing_required_field', 'values'))
    elif not isinstance(raw_values, dict):
        reasons.append(Rejection('invalid_type', 'values'))
    else:
        values = raw_values

    envelope = None
    if not reasons:
        envelope = TelemetryEnvelope(
            message_id=message_id,
            schema_version=schema_version,
            occurred_at=occurred_at,
            values=values,
        )

    return EnvelopeInspection(decoded, envelope, message_id, content_hash, tuple(reasons))


def inspect_unprofiled_telemetry(raw: bytes, *, received_at: datetime) -> EnvelopeInspection:
    """Normalize the profile-less payload form at the MQTT ingress boundary."""
    if len(raw) > MAX_TELEMETRY_PAYLOAD_BYTES:
        return EnvelopeInspection(None, None, None, None, (Rejection('payload_too_large'),))

    decoded, content_hash = _decode_and_hash(raw)
    if decoded is None:
        return EnvelopeInspection(None, None, None, None, (Rejection('invalid_json'),))

    reasons: list[Rejection] = []
    raw_message_id = decoded.get('message_id')
    message_id: str | None = None
    if 'message_id' not in decoded:
        message_id = str(uuid4())
    elif not isinstance(raw_message_id, str) or not raw_message_id:
        reasons.append(Rejection('invalid_type', 'message_id'))
    elif len(raw_message_id) > MAX_MESSAGE_ID_LENGTH:
        reasons.append(Rejection('invalid_payload', 'message_id'))
    else:
        message_id = raw_message_id

    raw_schema_version = decoded.get('schema_version')
    schema_version: int | None = None
    if 'schema_version' in decoded:
        if isinstance(raw_schema_version, bool) or not isinstance(raw_schema_version, int):
            reasons.append(Rejection('invalid_type', 'schema_version'))
        elif raw_schema_version < 1:
            reasons.append(Rejection('invalid_payload', 'schema_version'))
        else:
            schema_version = raw_schema_version

    raw_occurred_at = decoded.get('occurred_at')
    occurred_at: datetime | None = None
    if 'occurred_at' not in decoded:
        occurred_at = received_at.astimezone(timezone.utc)
    elif not isinstance(raw_occurred_at, str):
        reasons.append(Rejection('invalid_type', 'occurred_at'))
    else:
        occurred_at = _parse_occurred_at(raw_occurred_at)
        if occurred_at is None:
            reasons.append(Rejection('invalid_type', 'occurred_at'))
        elif occurred_at > received_at.astimezone(timezone.utc) + MAX_FUTURE_SKEW:
            reasons.append(Rejection('occurred_at_too_far_in_future', 'occurred_at'))

    reserved_fields = {'message_id', 'occurred_at', 'schema_version'}
    raw_values = decoded.get('values')
    if 'values' in decoded:
        values: dict[str, Any] = raw_values if isinstance(raw_values, dict) else {}
        if not isinstance(raw_values, dict):
            reasons.append(Rejection('invalid_type', 'values'))
        elif not raw_values:
            reasons.append(Rejection('invalid_payload', 'values'))
        mixed_fields = sorted(set(decoded) - reserved_fields - {'values'})
        if mixed_fields:
            reasons.append(Rejection('invalid_payload', mixed_fields[0]))
    else:
        values = {key: value for key, value in decoded.items() if key not in reserved_fields}
        if not values:
            reasons.append(Rejection('invalid_payload', 'values'))

    for name, value in values.items():
        if value is None or isinstance(value, (dict, list)) or isinstance(value, float) and not math.isfinite(value):
            reasons.append(Rejection('invalid_type', name))

    envelope = None
    if not reasons:
        assert message_id is not None and occurred_at is not None
        envelope = TelemetryEnvelope(
            message_id=message_id,
            schema_version=schema_version,
            occurred_at=occurred_at,
            values=values,
        )

    return EnvelopeInspection(decoded, envelope, message_id, content_hash, tuple(reasons))


def _decode_and_hash(raw: bytes) -> tuple[dict[str, Any] | None, str | None]:
    try:
        decoded = json.loads(
            raw.decode('utf-8'),
            object_pairs_hook=_object_without_duplicate_keys,
            parse_constant=_reject_non_finite_number,
        )
    except (UnicodeDecodeError, json.JSONDecodeError, ValueError):
        return None, None

    if not isinstance(decoded, dict):
        return None, None

    try:
        canonical = json.dumps(
            decoded,
            sort_keys=True,
            separators=(',', ':'),
            ensure_ascii=False,
            allow_nan=False,
        )
        if any(0xD800 <= ord(character) <= 0xDFFF for character in canonical) or '\x00' in canonical:
            raise ValueError('PostgreSQL JSONB cannot store this Unicode value.')
        content_hash = hashlib.sha256(canonical.encode('utf-8')).hexdigest()
    except (TypeError, ValueError, UnicodeEncodeError):
        return None, None

    return decoded, content_hash


def _object_without_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    value: dict[str, Any] = {}
    for key, item in pairs:
        if key in value:
            raise ValueError('JSON object contains a duplicate key.')
        value[key] = item
    return value


def _reject_non_finite_number(value: str) -> None:
    raise ValueError(f'Invalid JSON number: {value}')


def _parse_occurred_at(value: str) -> datetime | None:
    if 'T' not in value:
        return None

    normalized_value = value[:-1] + '+00:00' if value.endswith(('Z', 'z')) else value
    try:
        parsed = datetime.fromisoformat(normalized_value)
    except ValueError:
        return None

    if parsed.tzinfo is None or parsed.utcoffset() is None:
        return None

    return parsed.astimezone(timezone.utc)
