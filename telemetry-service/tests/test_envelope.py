from __future__ import annotations

import hashlib
import json
import unittest
from datetime import datetime, timezone

from app.telemetry.envelope import (
    MAX_MESSAGE_ID_LENGTH,
    MAX_TELEMETRY_PAYLOAD_BYTES,
    inspect_telemetry_envelope,
    inspect_unprofiled_telemetry,
)
from app.telemetry.validation import Rejection

RECEIVED_AT = datetime(2026, 10, 11, 4, 0, tzinfo=timezone.utc)
VALID_PAYLOAD = {
    'message_id': 'message-1',
    'schema_version': 1,
    'occurred_at': '2026-10-11T03:59:00+07:00',
    'values': {'temperature': 40},
}


class InspectTelemetryEnvelopeTest(unittest.TestCase):
    def test_accepts_timezone_aware_envelope_and_normalizes_occurred_at_to_utc(self) -> None:
        inspection = _inspect(VALID_PAYLOAD)

        self.assertEqual((), inspection.reasons)
        self.assertIsNotNone(inspection.envelope)
        self.assertEqual('message-1', inspection.envelope.message_id)
        self.assertEqual(1, inspection.envelope.schema_version)
        self.assertEqual(datetime(2026, 10, 10, 20, 59, tzinfo=timezone.utc), inspection.envelope.occurred_at)
        self.assertEqual({'temperature': 40}, inspection.envelope.values)
        self.assertEqual(64, len(inspection.content_hash))

    def test_rejects_missing_required_envelope_fields(self) -> None:
        for field in ('message_id', 'schema_version', 'occurred_at', 'values'):
            with self.subTest(field=field):
                payload = dict(VALID_PAYLOAD)
                del payload[field]

                result = _inspect(payload)

                self.assertIn(Rejection('missing_required_field', field), result.reasons)
                self.assertIsNone(result.envelope)

    def test_requires_a_nonempty_message_id_of_at_most_128_characters(self) -> None:
        for message_id, expected in (
            ('', Rejection('invalid_type', 'message_id')),
            ('x' * (MAX_MESSAGE_ID_LENGTH + 1), Rejection('invalid_payload', 'message_id')),
        ):
            with self.subTest(length=len(message_id)):
                self.assertIn(expected, _inspect({**VALID_PAYLOAD, 'message_id': message_id}).reasons)

    def test_requires_positive_integer_schema_version_and_rejects_booleans(self) -> None:
        for schema_version, expected in (
            (True, Rejection('invalid_type', 'schema_version')),
            (1.0, Rejection('invalid_type', 'schema_version')),
            (0, Rejection('invalid_payload', 'schema_version')),
        ):
            with self.subTest(schema_version=schema_version):
                self.assertIn(expected, _inspect({**VALID_PAYLOAD, 'schema_version': schema_version}).reasons)

    def test_requires_values_to_be_an_object(self) -> None:
        for values in (None, [], 'temperature=40'):
            with self.subTest(values=values):
                self.assertIn(Rejection('invalid_type', 'values'), _inspect({**VALID_PAYLOAD, 'values': values}).reasons)

    def test_rejects_missing_timezone_and_more_than_five_minutes_of_future_skew(self) -> None:
        for occurred_at in ('2026-10-11T04:00:00', 'not-a-date'):
            with self.subTest(occurred_at=occurred_at):
                result = _inspect({**VALID_PAYLOAD, 'occurred_at': occurred_at})
                self.assertIn(Rejection('invalid_type', 'occurred_at'), result.reasons)

        future = _inspect({**VALID_PAYLOAD, 'occurred_at': '2026-10-11T11:06:00+07:00'})
        self.assertIn(Rejection('occurred_at_too_far_in_future', 'occurred_at'), future.reasons)

    def test_accepts_late_data_and_future_times_within_five_minutes(self) -> None:
        old_message = _inspect({**VALID_PAYLOAD, 'occurred_at': '2000-01-01T00:00:00Z'})
        near_future = _inspect({**VALID_PAYLOAD, 'occurred_at': '2026-10-11T04:05:00Z'})

        self.assertEqual((), old_message.reasons)
        self.assertEqual((), near_future.reasons)

    def test_enforces_64_kib_limit_before_json_decode(self) -> None:
        valid_raw = json.dumps(VALID_PAYLOAD, separators=(',', ':')).encode()
        at_limit = valid_raw + b' ' * (MAX_TELEMETRY_PAYLOAD_BYTES - len(valid_raw))

        self.assertEqual((), inspect_telemetry_envelope(at_limit, received_at=RECEIVED_AT).reasons)
        too_large = inspect_telemetry_envelope(at_limit + b' ', received_at=RECEIVED_AT)
        self.assertEqual((Rejection('payload_too_large'),), too_large.reasons)
        self.assertIsNone(too_large.payload)
        self.assertIsNone(too_large.content_hash)

    def test_hashes_canonical_json_and_rejects_nonstandard_json(self) -> None:
        same_content_reordered = b'{"values":{"temperature":40},"occurred_at":"2026-10-11T03:59:00+07:00","schema_version":1,"message_id":"message-1"}'

        self.assertEqual(_inspect(VALID_PAYLOAD).content_hash, inspect_telemetry_envelope(
            same_content_reordered,
            received_at=RECEIVED_AT,
        ).content_hash)
        for raw in (b'{"a":1,"a":2}', b'{"values":{"x":NaN}}', b'[]'):
            with self.subTest(raw=raw):
                self.assertEqual(
                    (Rejection('invalid_json'),),
                    inspect_telemetry_envelope(raw, received_at=RECEIVED_AT).reasons,
                )

    def test_accepts_flat_payload_and_supplies_message_id_and_received_timestamp(self) -> None:
        raw = b'{"temperature":25.12,"humidity":60,"enabled":true,"mode":"auto"}'

        result = inspect_unprofiled_telemetry(raw, received_at=RECEIVED_AT)

        self.assertEqual((), result.reasons)
        self.assertIsNotNone(result.envelope)
        self.assertEqual({'temperature': 25.12, 'humidity': 60, 'enabled': True, 'mode': 'auto'}, result.envelope.values)
        self.assertEqual(RECEIVED_AT, result.envelope.occurred_at)
        self.assertIsNone(result.envelope.schema_version)
        self.assertEqual(result.envelope.message_id, result.message_id)
        canonical = json.dumps(json.loads(raw), sort_keys=True, separators=(',', ':'), ensure_ascii=False)
        self.assertEqual(hashlib.sha256(canonical.encode()).hexdigest(), result.content_hash)
        self.assertEqual(raw.decode(), json.dumps(result.payload, separators=(',', ':')))

    def test_accepts_wrapped_values_and_keeps_supplied_metadata(self) -> None:
        result = _inspect_unprofiled({
            'message_id': 'client-message',
            'schema_version': 7,
            'occurred_at': '2026-10-11T03:59:00Z',
            'values': {'temperature': 25.12},
        })

        self.assertEqual((), result.reasons)
        self.assertEqual('client-message', result.envelope.message_id)
        self.assertEqual(7, result.envelope.schema_version)
        self.assertEqual(datetime(2026, 10, 11, 3, 59, tzinfo=timezone.utc), result.envelope.occurred_at)
        self.assertEqual({'temperature': 25.12}, result.envelope.values)

    def test_rejects_mixed_metric_sources_and_empty_metrics(self) -> None:
        for payload, field in (
            ({'temperature': 25, 'values': {'humidity': 60}}, 'temperature'),
            ({'message_id': 'client-message'}, 'values'),
            ({'values': {}}, 'values'),
        ):
            with self.subTest(payload=payload):
                self.assertIn(Rejection('invalid_payload', field), _inspect_unprofiled(payload).reasons)

    def test_rejects_non_scalar_metric_values(self) -> None:
        for value in (None, {}, [], {'nested': 1}):
            with self.subTest(value=value):
                result = _inspect_unprofiled({'values': {'metric': value}})
                self.assertIn(Rejection('invalid_type', 'metric'), result.reasons)

    def test_rejects_invalid_or_null_metadata_instead_of_defaulting_it(self) -> None:
        for field in ('message_id', 'occurred_at', 'schema_version'):
            with self.subTest(field=field):
                result = _inspect_unprofiled({'values': {'temperature': 25}, field: None})
                self.assertIn(Rejection('invalid_type', field), result.reasons)

        for field, value, expected in (
            ('message_id', 42, Rejection('invalid_type', 'message_id')),
            ('message_id', '', Rejection('invalid_type', 'message_id')),
            ('message_id', 'x' * (MAX_MESSAGE_ID_LENGTH + 1), Rejection('invalid_payload', 'message_id')),
            ('occurred_at', 42, Rejection('invalid_type', 'occurred_at')),
            ('occurred_at', '2026-10-11T04:00:00', Rejection('invalid_type', 'occurred_at')),
            ('schema_version', True, Rejection('invalid_type', 'schema_version')),
            ('schema_version', 0, Rejection('invalid_payload', 'schema_version')),
        ):
            with self.subTest(field=field, value=value):
                result = _inspect_unprofiled({'values': {'temperature': 25}, field: value})
                self.assertIn(expected, result.reasons)

    def test_checks_future_timestamp_payload_size_and_preserves_raw_hash(self) -> None:
        future = _inspect_unprofiled({
            'message_id': 'client-message',
            'occurred_at': '2026-10-11T04:06:00Z',
            'temperature': 25,
        })
        self.assertIn(Rejection('occurred_at_too_far_in_future', 'occurred_at'), future.reasons)

        valid_raw = b'{"temperature":25}'
        at_limit = valid_raw + b' ' * (MAX_TELEMETRY_PAYLOAD_BYTES - len(valid_raw))
        self.assertEqual((), inspect_unprofiled_telemetry(at_limit, received_at=RECEIVED_AT).reasons)
        too_large = inspect_unprofiled_telemetry(at_limit + b' ', received_at=RECEIVED_AT)
        self.assertEqual((Rejection('payload_too_large'),), too_large.reasons)
        self.assertIsNone(too_large.content_hash)
        self.assertEqual(
            (Rejection('invalid_json'),),
            inspect_unprofiled_telemetry(b'{"temperature":NaN}', received_at=RECEIVED_AT).reasons,
        )

        raw = b'{"temperature":25,"message_id":"client-message"}'
        self.assertEqual(
            inspect_unprofiled_telemetry(raw, received_at=RECEIVED_AT).content_hash,
            inspect_unprofiled_telemetry(b'{"message_id":"client-message","temperature":25}', received_at=RECEIVED_AT).content_hash,
        )


def _inspect(payload: dict[str, object]):
    return inspect_telemetry_envelope(
        json.dumps(payload, separators=(',', ':')).encode(),
        received_at=RECEIVED_AT,
    )


def _inspect_unprofiled(payload: dict[str, object]):
    return inspect_unprofiled_telemetry(
        json.dumps(payload, separators=(',', ':')).encode(),
        received_at=RECEIVED_AT,
    )
