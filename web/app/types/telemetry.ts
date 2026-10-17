import { z } from 'zod'

export const telemetryIntervalSchema = z.enum(['1m', '5m', '15m', '1h', '1d'])

export const telemetrySampleSchema = z.object({
  id: z.string().uuid(),
  project_id: z.string().uuid(),
  device_id: z.string().uuid(),
  device_profile_version_id: z.string().uuid().nullable(),
  message_id: z.string(),
  occurred_at: z.string(),
  received_at: z.string(),
  values: z.record(z.string(), z.union([z.number(), z.string(), z.boolean()]))
})

export const telemetryLatestValueSchema = z.object({
  metric: z.string(),
  value: z.union([z.number(), z.string(), z.boolean()]),
  sample_id: z.string().uuid(),
  message_id: z.string(),
  occurred_at: z.string(),
  received_at: z.string()
})

export const telemetryLatestResponseSchema = z.object({
  data: z.array(telemetryLatestValueSchema)
})

export const telemetryHistoryResponseSchema = z.object({
  data: z.array(telemetrySampleSchema),
  meta: z.object({
    next_cursor: z.string().nullable()
  })
})

export const telemetryAggregateSchema = z.object({
  bucket_start: z.string(),
  metric: z.string(),
  avg: z.number(),
  min: z.number(),
  max: z.number(),
  count: z.number().int()
})

export const telemetryAggregatesResponseSchema = z.object({
  data: z.array(telemetryAggregateSchema)
})

export const telemetryIngestStatusSchema = z.enum(['persisted', 'rejected', 'duplicate', 'idempotency_conflict', 'unresolved', 'skipped'])

export const telemetryIngestOutcomeSchema = z.object({
  id: z.string().uuid(),
  project_id: z.string().uuid(),
  device_id: z.string().uuid(),
  processing_id: z.string().uuid(),
  message_id: z.string().nullable(),
  status: telemetryIngestStatusSchema,
  reason_codes: z.array(z.object({
    code: z.string(),
    field: z.string().nullable()
  })),
  stages: z.array(z.object({
    stage: z.string(),
    at: z.string(),
    subscribers: z.number().int().optional()
  })),
  received_at: z.string()
})

export const telemetryOutcomesResponseSchema = z.object({
  data: z.array(telemetryIngestOutcomeSchema)
})

export const telemetryTraceResponseSchema = z.object({
  data: z.object({
    outcomes: z.array(telemetryIngestOutcomeSchema),
    sample: telemetrySampleSchema.nullable()
  })
})

export const telemetryStreamTicketResponseSchema = z.object({
  data: z.object({
    ticket: z.string(),
    expires_at: z.string()
  })
})

export const devicePresenceStreamTicketResponseSchema = telemetryStreamTicketResponseSchema

export const telemetryUnresolvedMessageSchema = z.object({
  processing_id: z.string().uuid(),
  topic: z.string(),
  reason: z.enum(['invalid_topic', 'device_not_found', 'project_mismatch']),
  payload_size: z.number().int().nonnegative(),
  stages: z.array(z.object({
    stage: z.string(),
    at: z.string()
  })),
  received_at: z.string()
})

export const telemetryUnresolvedMessagesResponseSchema = z.object({
  data: z.array(telemetryUnresolvedMessageSchema)
})

export const telemetryUnresolvedMessageResponseSchema = z.object({
  data: telemetryUnresolvedMessageSchema
})

export const telemetryStreamEventSchema = z.discriminatedUnion('type', [
  z.object({
    type: z.literal('telemetry.sample'),
    data: telemetrySampleSchema
  }),
  z.object({
    type: z.literal('telemetry.outcome'),
    data: telemetryIngestOutcomeSchema
  })
])

export const devicePresenceEventDataSchema = z.object({
  project_id: z.string().uuid(),
  device_id: z.string().uuid(),
  connection_state: z.enum(['online', 'unstable', 'offline']),
  last_seen_at: z.string().datetime({ offset: true }).nullable(),
  updated_at: z.string().datetime({ offset: true })
})

export const devicePresenceStreamEventSchema = z.object({
  type: z.literal('device.presence'),
  data: devicePresenceEventDataSchema
})

export type TelemetryInterval = z.infer<typeof telemetryIntervalSchema>
export type TelemetrySample = z.infer<typeof telemetrySampleSchema>
export type TelemetryLatestValue = z.infer<typeof telemetryLatestValueSchema>
export type TelemetryUnresolvedMessage = z.infer<typeof telemetryUnresolvedMessageSchema>
export type TelemetryIngestStatus = z.infer<typeof telemetryIngestStatusSchema>
export type TelemetryAggregate = z.infer<typeof telemetryAggregateSchema>
export type TelemetryIngestOutcome = z.infer<typeof telemetryIngestOutcomeSchema>
export type TelemetryTrace = z.infer<typeof telemetryTraceResponseSchema>['data']
export type DevicePresenceEventData = z.infer<typeof devicePresenceEventDataSchema>
export type DevicePresenceStreamEvent = z.infer<typeof devicePresenceStreamEventSchema>
