from __future__ import annotations

import asyncio
import json
import logging
import time
from dataclasses import dataclass
from typing import Any
from uuid import uuid4

from asyncpg import Connection
from asyncpg.pool import Pool

from app.rules.definition import InvalidRuleDefinition, RuleRevision, parse_rule_revision
from app.rules.engine import Decision, EvaluatedSample, EvaluationState, evaluate
from app.storage.alerting import iso, record_alert_transition
from app.storage.queries import (
    CHANGE_ALERT_SEVERITY_SQL,
    CLEAR_ALERT_SQL,
    INSERT_ALERT_SQL,
    MARK_EVALUATION_JOBS_DONE_SQL,
    SELECT_ACTIVE_DEVICE_ALERTS_SQL,
    SELECT_ACTIVE_RULES_FOR_VERSIONS_SQL,
    SELECT_EVALUATION_STATES_SQL,
    SELECT_PENDING_EVALUATION_DEVICES_SQL,
    SELECT_PENDING_EVALUATION_JOBS_SQL,
    TOUCH_ALERT_SQL,
    TRY_LOCK_EVALUATION_DEVICE_SQL,
    UPSERT_EVALUATION_STATE_SQL,
)

LOGGER = logging.getLogger(__name__)
DEFAULT_POLL_SECONDS = 1.0
DEFAULT_BATCH_SIZE = 500
CANDIDATE_DEVICES = 20


@dataclass
class _ActiveAlert:
    id: str
    severity: str


class PostgresRuleEvaluator:
    """Evaluates queued samples per device; one transaction covers state, alerts, history and outbox."""

    def __init__(
        self,
        db_pool: Pool,
        *,
        poll_seconds: float = DEFAULT_POLL_SECONDS,
        batch_size: int = DEFAULT_BATCH_SIZE,
    ) -> None:
        self._db_pool = db_pool
        self._poll_seconds = poll_seconds
        self._batch_size = batch_size

    async def run(self) -> None:
        # shortcut: polling; switch to LISTEN/NOTIFY if sub-second evaluation latency matters.
        while True:
            try:
                processed = await self.run_once()
            except Exception:
                LOGGER.exception('rule_evaluation_failed')
                processed = 0
            if processed == 0:
                await asyncio.sleep(self._poll_seconds)

    async def run_once(self) -> int:
        """Evaluates one device's pending jobs; returns how many jobs were marked evaluated."""
        async with self._db_pool.acquire() as connection:
            async with connection.transaction():
                candidates = await connection.fetch(SELECT_PENDING_EVALUATION_DEVICES_SQL, CANDIDATE_DEVICES)
                for candidate in candidates:
                    device_id = str(candidate['device_id'])
                    # A non-blocking per-device lock keeps samples of one device in order across workers.
                    if await connection.fetchval(TRY_LOCK_EVALUATION_DEVICE_SQL, device_id):
                        return await self._evaluate_device(connection, device_id)
        return 0

    async def _evaluate_device(self, connection: Connection, device_id: str) -> int:
        started = time.perf_counter()
        jobs = await connection.fetch(SELECT_PENDING_EVALUATION_JOBS_SQL, device_id, self._batch_size)
        if not jobs:
            return 0
        project_id = str(jobs[0]['project_id'])
        version_ids = sorted({str(job['device_profile_version_id']) for job in jobs})

        rules_by_version: dict[str, list[RuleRevision]] = {}
        for row in await connection.fetch(SELECT_ACTIVE_RULES_FOR_VERSIONS_SQL, version_ids, project_id):
            try:
                rule = parse_rule_revision(
                    rule_id=str(row['rule_id']),
                    rule_code=row['rule_code'],
                    revision_id=str(row['revision_id']),
                    revision=row['revision'],
                    definition=row['definition'],
                )
            except InvalidRuleDefinition:
                LOGGER.exception('rule_definition_invalid', extra={'rule_id': str(row['rule_id'])})
                continue
            rules_by_version.setdefault(str(row['device_profile_version_id']), []).append(rule)

        states = {
            str(row['alert_rule_id']): EvaluationState(
                revision_id=str(row['alert_rule_revision_id']),
                trigger_since=row['trigger_since'],
                clear_since=row['clear_since'],
                cursor_received_at=row['cursor_received_at'],
                cursor_sample_id=None if row['cursor_sample_id'] is None else str(row['cursor_sample_id']),
            )
            for row in await connection.fetch(SELECT_EVALUATION_STATES_SQL, device_id)
        }
        alerts = {
            str(row['alert_rule_id']): _ActiveAlert(id=str(row['id']), severity=row['severity'])
            for row in await connection.fetch(SELECT_ACTIVE_DEVICE_ALERTS_SQL, device_id)
        }
        touched: dict[str, RuleRevision] = {}

        for job in jobs:
            values = job['values']
            sample = EvaluatedSample(
                id=str(job['sample_id']),
                message_id=job['message_id'],
                received_at=job['received_at'],
                values=json.loads(values) if isinstance(values, str) else values,
            )
            for rule in rules_by_version.get(str(job['device_profile_version_id']), []):
                active = alerts.get(rule.rule_id)
                decision = evaluate(
                    states.get(rule.rule_id, EvaluationState()),
                    rule,
                    None if active is None else active.severity,
                    sample,
                )
                if decision.stale:
                    continue
                states[rule.rule_id] = decision.state
                touched[rule.rule_id] = rule
                await self._apply(connection, job, sample, rule, decision, active, alerts)

        for rule_id, rule in touched.items():
            state = states[rule_id]
            await connection.execute(
                UPSERT_EVALUATION_STATE_SQL,
                device_id,
                rule_id,
                project_id,
                rule.revision_id,
                state.trigger_since,
                state.clear_since,
                state.cursor_received_at,
                state.cursor_sample_id,
            )
        await connection.execute(MARK_EVALUATION_JOBS_DONE_SQL, [job['id'] for job in jobs])
        LOGGER.info(
            'rule_evaluation_batch',
            extra={'device_id': device_id, 'jobs': len(jobs), 'duration_ms': round((time.perf_counter() - started) * 1000, 3)},
        )
        return len(jobs)

    async def _apply(
        self,
        connection: Connection,
        job: Any,
        sample: EvaluatedSample,
        rule: RuleRevision,
        decision: Decision,
        active: _ActiveAlert | None,
        alerts: dict[str, _ActiveAlert],
    ) -> None:
        project_id = str(job['project_id'])
        evidence = {
            'field': rule.field,
            'value': decision.value,
            'received_at': iso(sample.received_at),
            'occurred_at': iso(job['occurred_at']),
        }

        if active is not None and decision.severity_changed:
            await connection.execute(CHANGE_ALERT_SEVERITY_SQL, active.id, rule.severity, rule.revision_id)
            await record_alert_transition(
                connection,
                alert_id=active.id,
                project_id=project_id,
                event_type='severity_changed',
                occurred_at=sample.received_at,
                revision_id=rule.revision_id,
                sample_id=sample.id,
                message_id=sample.message_id,
                data={'from': active.severity, 'to': rule.severity},
                idempotency_suffix=f':{rule.revision_id}',
            )
            active.severity = rule.severity

        if decision.action == 'open':
            alert_id = str(uuid4())
            await connection.execute(
                INSERT_ALERT_SQL,
                alert_id,
                project_id,
                str(job['device_id']),
                None if job['equipment_asset_id'] is None else str(job['equipment_asset_id']),
                rule.rule_id,
                rule.revision_id,
                str(job['device_profile_version_id']),
                rule.severity,
                sample.received_at,
                sample.id,
                sample.message_id,
                json.dumps({
                    **evidence,
                    'operator': rule.trigger.operator,
                    'threshold': rule.trigger.value,
                    'duration_seconds': int(rule.trigger.duration.total_seconds()),
                }),
            )
            await record_alert_transition(
                connection,
                alert_id=alert_id,
                project_id=project_id,
                event_type='opened',
                occurred_at=sample.received_at,
                revision_id=rule.revision_id,
                sample_id=sample.id,
                message_id=sample.message_id,
                data=evidence,
            )
            alerts[rule.rule_id] = _ActiveAlert(id=alert_id, severity=rule.severity)
        elif decision.action == 'seen' and active is not None:
            await connection.execute(TOUCH_ALERT_SQL, active.id, sample.received_at)
        elif decision.action == 'clear' and active is not None:
            await connection.execute(CLEAR_ALERT_SQL, active.id, sample.received_at, sample.id, sample.message_id)
            await record_alert_transition(
                connection,
                alert_id=active.id,
                project_id=project_id,
                event_type='cleared',
                occurred_at=sample.received_at,
                revision_id=rule.revision_id,
                sample_id=sample.id,
                message_id=sample.message_id,
                data={**evidence, 'clear_reason': 'condition_cleared'},
            )
            del alerts[rule.rule_id]

