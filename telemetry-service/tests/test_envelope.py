from __future__ import annotations

import json
import unittest
from datetime import datetime, timezone

from app.telemetry.envelope import (
    MAX_MESSAGE_ID_LENGTH,
    MAX_TELEMETRY_PAYLOAD_BYTES,
    inspect_telemetry_envelope,
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


def _inspect(payload: dict[str, object]):
    return inspect_telemetry_envelope(
        json.dumps(payload, separators=(',', ':')).encode(),
        received_at=RECEIVED_AT,
    )
