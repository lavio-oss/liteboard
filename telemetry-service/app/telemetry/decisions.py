"""Pure ingest decisions. The storage layer reads state, calls these, and executes the result."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Literal

from app.telemetry.envelope import EnvelopeInspection, TelemetryEnvelope
from app.telemetry.validation import Rejection, validate_telemetry

CentralReason = Literal['device_not_found', 'project_mismatch']


@dataclass(frozen=True)
class DeviceScope:
    """A device row looked up by ID alone, before its topic project is trusted."""

    project_id: str
    status: str
    approval_status: str
    project_status: str
    tenant_status: str


@dataclass(frozen=True)
class ProfileAssignment:
    version: int
    definition: dict[str, Any]


@dataclass(frozen=True)
class Reject:
    reasons: tuple[Rejection, ...]


@dataclass(frozen=True)
class Duplicate:
    status: Literal['duplicate', 'idempotency_conflict']
    reasons: tuple[Rejection, ...]


@dataclass(frozen=True)
class Persist:
    envelope: TelemetryEnvelope


def classify_unresolved(scope: DeviceScope | None, topic_project_id: str) -> CentralReason | Rejection:
    """Central reason when the DB cannot confirm the topic's (project, device) pair; otherwise the
    tenant-owned rejection explaining why that confirmed device is blocked."""
    if scope is None:
        return 'device_not_found'
    if scope.project_id != topic_project_id:
        return 'project_mismatch'
    if scope.approval_status != 'approved':
        return Rejection('device_not_approved')
    if scope.status != 'active':
        return Rejection('device_inactive')
    if scope.project_status != 'active':
        return Rejection('project_inactive')
    return Rejection('tenant_inactive')


def classify_duplicate(content_hash: str, existing_hash: str | None) -> Duplicate | None:
    if existing_hash is None:
        return None
    if existing_hash == content_hash:
        return Duplicate('duplicate', ())
    return Duplicate('idempotency_conflict', (Rejection('idempotency_conflict', 'message_id'),))


def decide_admission(inspection: EnvelopeInspection, profile: ProfileAssignment) -> Reject | Persist:
    if inspection.reasons:
        return Reject(inspection.reasons)

    validation = validate_telemetry(
        definition=profile.definition,
        version=profile.version,
        payload=inspection.payload,
    )
    if validation.reasons:
        return Reject(tuple(validation.reasons))
    if inspection.envelope is None:
        raise AssertionError('A reason-free inspection must carry an envelope.')
    return Persist(inspection.envelope)
