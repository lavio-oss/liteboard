import assert from 'node:assert/strict'
import test from 'node:test'
import { overviewResponseSchema } from '../app/types/overview.ts'

const id = '00000000-0000-4000-8000-000000000001'
const at = '2026-10-19T12:00:00.000000Z'

const overview = {
  generated_at: at,
  window_hours: 24,
  devices: {
    total: 3, online: 1, offline: 2, unstable: 0, pending_approval: 1, unassigned: 1,
    attention: [{ id, name: 'Pump A', code: 'pump-a', connection_state: 'offline', approval_status: 'pending', last_seen_at: null, reason: 'pending_approval' }]
  },
  alerts: {
    active: 2,
    active_unacknowledged: 1,
    active_by_severity: { critical: 1, major: 1, minor: 0, warning: 0 },
    opened_in_window: 2,
    top_rules: [{ id, name: 'Overheat', opened: 2 }],
    unacknowledged: [{ id, severity: 'critical', rule_name: 'Overheat', device_name: 'Pump A', opened_at: at }]
  },
  ingest: {
    totals: { persisted: 2, rejected: 2, duplicate: 1, idempotency_conflict: 0, unresolved: 0, skipped: 0 },
    buckets: [{ start: at, persisted: 1, rejected: 2, other: 1 }],
    top_reasons: [{ code: 'schema_version_mismatch', count: 2 }]
  },
  pipeline: {
    evaluation_latency_p50_ms: 12.5,
    evaluation_latency_p95_ms: null,
    pending_evaluation_jobs: 0,
    oldest_pending_job_age_s: null,
    outbox_pending: 1,
    oldest_outbox_age_s: 60,
    deliveries: { pending: 0, in_flight: 0, succeeded: 3, failed: 0, exhausted: 1 },
    failing_endpoints: [{ id, name: 'Pager', failed: 1, last_error: 'http_500' }]
  }
}

test('overview response matches the ProjectOverviewService shape', () => {
  assert.equal(overviewResponseSchema.safeParse({ data: overview }).success, true)
  // Operators and viewers get no pipeline section.
  assert.equal(overviewResponseSchema.safeParse({ data: { ...overview, pipeline: null } }).success, true)
})

test('overview response rejects missing counters and unknown attention reasons', () => {
  const { skipped: _skipped, ...totals } = overview.ingest.totals

  assert.equal(overviewResponseSchema.safeParse({ data: { ...overview, ingest: { ...overview.ingest, totals } } }).success, false)
  assert.equal(overviewResponseSchema.safeParse({
    data: { ...overview, devices: { ...overview.devices, attention: [{ ...overview.devices.attention[0], reason: 'broken' }] } }
  }).success, false)
})
