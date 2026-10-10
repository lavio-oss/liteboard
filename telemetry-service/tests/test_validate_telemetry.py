from __future__ import annotations

import unittest

from app.application.validate_telemetry import Rejection, validate_telemetry

MOTOR_V1 = {
    'strict_mode': True,
    'fields': {
        'temperature': {'type': 'float', 'unit': 'celsius', 'required': True, 'min': -20, 'max': 150},
        'vibration': {'type': 'float', 'unit': 'mm/s RMS', 'required': True},
        'rpm': {'type': 'integer', 'unit': 'rpm', 'required': True, 'min': 0},
    },
}

VALID_VALUES = {'temperature': 36.5, 'vibration': 1.2, 'rpm': 1450}


def _validate(values: object, *, definition: dict | None = None, **payload: object):
    return validate_telemetry(
        definition=definition or MOTOR_V1,
        version=1,
        payload={'values': values, **payload},
    )


class ValidateTelemetryTest(unittest.TestCase):
    def test_accepts_payload_matching_the_schema(self) -> None:
        result = _validate(VALID_VALUES)

        self.assertTrue(result.accepted)
        self.assertEqual((), result.reasons)

    def test_accepts_integers_for_float_fields(self) -> None:
        self.assertTrue(_validate({**VALID_VALUES, 'temperature': 36}).accepted)

    def test_rejects_missing_required_field(self) -> None:
        values = {key: value for key, value in VALID_VALUES.items() if key != 'rpm'}

        self.assertEqual((Rejection('missing_required_field', 'rpm'),), _validate(values).reasons)

    def test_accepts_missing_optional_field(self) -> None:
        definition = {
            'strict_mode': True,
            'fields': {**MOTOR_V1['fields'], 'humidity': {'type': 'float', 'unit': '%', 'required': False}},
        }

        self.assertTrue(_validate(VALID_VALUES, definition=definition).accepted)

    def test_rejects_undeclared_field_in_strict_mode(self) -> None:
        result = _validate({**VALID_VALUES, 'humidity': 40})

        self.assertEqual((Rejection('unknown_field', 'humidity'),), result.reasons)

    def test_ignores_undeclared_field_when_strict_mode_is_off(self) -> None:
        definition = {**MOTOR_V1, 'strict_mode': False}

        self.assertTrue(_validate({**VALID_VALUES, 'humidity': 40}, definition=definition).accepted)

    def test_rejects_values_of_the_wrong_type(self) -> None:
        cases = {
            'rpm': (12.5, True, '1450', None),
            'temperature': ('30', False, None, float('nan'), float('inf')),
        }

        for field, values in cases.items():
            for value in values:
                with self.subTest(field=field, value=value):
                    result = _validate({**VALID_VALUES, field: value})

                    self.assertEqual((Rejection('invalid_type', field),), result.reasons)

    def test_rejects_values_outside_min_and_max(self) -> None:
        self.assertEqual(
            (Rejection('below_minimum', 'temperature'),),
            _validate({**VALID_VALUES, 'temperature': -21}).reasons,
        )
        self.assertEqual(
            (Rejection('above_maximum', 'temperature'),),
            _validate({**VALID_VALUES, 'temperature': 151}).reasons,
        )
        self.assertEqual(
            (Rejection('below_minimum', 'rpm'),),
            _validate({**VALID_VALUES, 'rpm': -1}).reasons,
        )

    def test_accepts_values_on_the_min_and_max_boundary(self) -> None:
        self.assertTrue(_validate({**VALID_VALUES, 'temperature': -20}).accepted)
        self.assertTrue(_validate({**VALID_VALUES, 'temperature': 150}).accepted)
        self.assertTrue(_validate({**VALID_VALUES, 'rpm': 0}).accepted)

    def test_enforces_allowed_values_and_string_and_boolean_types(self) -> None:
        definition = {
            'strict_mode': True,
            'fields': {
                'state': {
                    'type': 'string',
                    'unit': 'code',
                    'required': True,
                    'allowed_values': ['running', 'stopped', 'fault'],
                },
                'door_open': {'type': 'boolean', 'unit': 'flag', 'required': True},
            },
        }

        self.assertTrue(_validate({'state': 'running', 'door_open': False}, definition=definition).accepted)
        self.assertEqual(
            (Rejection('value_not_allowed', 'state'),),
            _validate({'state': 'unknown', 'door_open': False}, definition=definition).reasons,
        )
        self.assertEqual(
            (Rejection('invalid_type', 'door_open'),),
            _validate({'state': 'running', 'door_open': 1}, definition=definition).reasons,
        )

    def test_reports_every_reason_ordered_by_field_name(self) -> None:
        result = _validate({'temperature': 200, 'rpm': 'fast', 'humidity': 40})

        self.assertFalse(result.accepted)
        self.assertEqual(
            (
                Rejection('unknown_field', 'humidity'),
                Rejection('invalid_type', 'rpm'),
                Rejection('above_maximum', 'temperature'),
                Rejection('missing_required_field', 'vibration'),
            ),
            result.reasons,
        )

    def test_rejects_payload_without_a_values_object(self) -> None:
        for payload in ({}, {'values': None}, {'values': [1, 2]}, {'values': 'temperature=30'}):
            with self.subTest(payload=payload):
                result = validate_telemetry(definition=MOTOR_V1, version=1, payload=payload)

                self.assertEqual((Rejection('invalid_payload'),), result.reasons)

    def test_rejects_a_schema_version_other_than_the_assigned_one(self) -> None:
        for schema_version in (2, 0, '1', True, None):
            with self.subTest(schema_version=schema_version):
                result = _validate(VALID_VALUES, schema_version=schema_version)

                self.assertEqual((Rejection('schema_version_mismatch'),), result.reasons)

    def test_never_switches_schema_when_the_device_claims_another_version(self) -> None:
        result = _validate({'temperature': 'not-checked'}, schema_version=2)

        self.assertEqual((Rejection('schema_version_mismatch'),), result.reasons)

    def test_validates_against_the_assigned_version_when_it_is_sent_or_omitted(self) -> None:
        self.assertTrue(_validate(VALID_VALUES, schema_version=1).accepted)
        self.assertTrue(_validate(VALID_VALUES).accepted)
        self.assertEqual(
            (Rejection('missing_required_field', 'rpm'),),
            _validate({'temperature': 36.5, 'vibration': 1.2}, schema_version=1).reasons,
        )
