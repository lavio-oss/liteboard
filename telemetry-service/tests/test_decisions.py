from __future__ import annotations

import unittest
from datetime import datetime, timezone

from app.telemetry.decisions import (
    DeviceScope,
    Duplicate,
    Persist,
    ProfileAssignment,
    Reject,
    classify_duplicate,
    classify_unresolved,
    decide_admission,
)
from app.telemetry.envelope import inspect_telemetry_envelope
from app.telemetry.validation import Rejection

PROJECT_ID = '123e4567-e89b-12d3-a456-426614174000'
RECEIVED_AT = datetime(2026, 10, 11, 4, 0, tzinfo=timezone.utc)
PROFILE = ProfileAssignment(
    version=1,
    definition={
        'strict_mode': True,
        'fields': {'temperature': {'type': 'float', 'unit': 'celsius', 'required': True, 'max': 150}},
    },
)
RAW = b'{"message_id":"msg-1","schema_version":1,"occurred_at":"2026-10-11T03:59:00Z","values":{"temperature":40}}'
ACTIVE_SCOPE = DeviceScope(
    project_id=PROJECT_ID,
    status='active',
    approval_status='approved',
    project_status='active',
    tenant_status='inactive',
)


class ClassifyUnresolvedTest(unittest.TestCase):
    def test_unconfirmed_scope_is_central(self) -> None:
        self.assertEqual('device_not_found', classify_unresolved(None, PROJECT_ID))
        self.assertEqual(
            'project_mismatch',
            classify_unresolved(ACTIVE_SCOPE, '123e4567-e89b-12d3-a456-426614174999'),
        )

    def test_confirmed_scope_reports_the_first_blocking_reason(self) -> None:
        cases = (
            ({'approval_status': 'pending', 'status': 'inactive'}, 'device_not_approved'),
            ({'status': 'inactive', 'project_status': 'inactive'}, 'device_inactive'),
            ({'project_status': 'inactive'}, 'project_inactive'),
            ({}, 'tenant_inactive'),
        )
        for overrides, reason in cases:
            with self.subTest(reason=reason):
                scope = DeviceScope(**{**ACTIVE_SCOPE.__dict__, **overrides})
                self.assertEqual(Rejection(reason), classify_unresolved(scope, PROJECT_ID))


class ClassifyDuplicateTest(unittest.TestCase):
    def test_new_identity_same_content_and_changed_content(self) -> None:
        self.assertIsNone(classify_duplicate('a' * 64, None))
        self.assertEqual(Duplicate('duplicate', ()), classify_duplicate('a' * 64, 'a' * 64))
        self.assertEqual(
            Duplicate('idempotency_conflict', (Rejection('idempotency_conflict', 'message_id'),)),
            classify_duplicate('a' * 64, 'b' * 64),
        )


class DecideAdmissionTest(unittest.TestCase):
    def test_valid_envelope_with_assigned_profile_is_persisted(self) -> None:
        decision = decide_admission(inspect_telemetry_envelope(RAW, received_at=RECEIVED_AT), PROFILE)

        self.assertIsInstance(decision, Persist)
        self.assertEqual('msg-1', decision.envelope.message_id)
        self.assertEqual({'temperature': 40}, decision.envelope.values)

    def test_schema_violation_is_rejected_with_its_reasons(self) -> None:
        raw = RAW.replace(b'40', b'200')

        decision = decide_admission(inspect_telemetry_envelope(raw, received_at=RECEIVED_AT), PROFILE)

        self.assertIsInstance(decision, Reject)
        self.assertEqual(['above_maximum'], [reason.code for reason in decision.reasons])
