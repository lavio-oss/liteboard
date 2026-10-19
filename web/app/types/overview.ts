import { z } from 'zod'
import { alertSeveritySchema } from './alerting.ts'

const count = z.number().int().nonnegative()

export const overviewSchema = z.object({
  generated_at: z.string(),
  window_hours: z.number().int().positive(),
  devices: z.object({
    total: z.number().int(),
    online: z.number().int(),
    offline: z.number().int(),
    unstable: z.number().int(),
    pending_approval: z.number().int(),
    unassigned: z.number().int(),
    attention: z.array(z.object({
      id: z.string().uuid(),
      name: z.string(),
      code: z.string(),
      connection_state: z.string(),
      approval_status: z.string(),
      last_seen_at: z.string().nullable(),
      reason: z.enum(['pending_approval', 'not_online', 'unassigned'])
    }))
  }),
  alerts: z.object({
    active: z.number().int(),
    active_unacknowledged: z.number().int(),
    active_by_severity: z.record(alertSeveritySchema, z.number().int()),
    opened_in_window: z.number().int(),
    top_rules: z.array(z.object({ id: z.string().uuid(), name: z.string(), opened: z.number().int() })),
    unacknowledged: z.array(z.object({
      id: z.string().uuid(),
      severity: alertSeveritySchema,
      rule_name: z.string(),
      device_name: z.string(),
      opened_at: z.string()
    }))
  }),
  ingest: z.object({
    totals: z.object({
      persisted: count,
      rejected: count,
      duplicate: count,
      idempotency_conflict: count,
      unresolved: count,
      skipped: count
    }),
    buckets: z.array(z.object({
      start: z.string(),
      persisted: z.number().int(),
      rejected: z.number().int(),
      other: z.number().int()
    })),
    top_reasons: z.array(z.object({ code: z.string(), count: z.number().int() }))
  }),
  // Only for project admins and engineers; null otherwise.
  pipeline: z.object({
    evaluation_latency_p50_ms: z.number().nullable(),
    evaluation_latency_p95_ms: z.number().nullable(),
    pending_evaluation_jobs: z.number().int(),
    oldest_pending_job_age_s: z.number().int().nullable(),
    outbox_pending: z.number().int(),
    oldest_outbox_age_s: z.number().int().nullable(),
    deliveries: z.object({ pending: count, in_flight: count, succeeded: count, failed: count, exhausted: count }),
    failing_endpoints: z.array(z.object({
      id: z.string().uuid(),
      name: z.string(),
      failed: z.number().int(),
      last_error: z.string().nullable()
    }))
  }).nullable()
})

export const overviewResponseSchema = z.object({ data: overviewSchema })

export type Overview = z.infer<typeof overviewSchema>
