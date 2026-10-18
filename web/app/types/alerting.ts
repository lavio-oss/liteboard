import { z } from 'zod'

export const comparisonOperatorSchema = z.enum(['gt', 'gte', 'lt', 'lte'])
export const alertSeveritySchema = z.enum(['critical', 'major', 'minor', 'warning'])
export const alertRuleStatusSchema = z.enum(['active', 'archived'])
export const alertClearReasonSchema = z.enum(['condition_cleared', 'device_offline', 'rule_archived'])
export const alertEventTypeSchema = z.enum(['opened', 'severity_changed', 'acknowledged', 'cleared'])
export const webhookEventTypeSchema = z.enum(['alert.opened', 'alert.cleared', 'alert.acknowledged', 'alert.severity_changed'])
export const webhookDeliveryStatusSchema = z.enum(['pending', 'in_flight', 'succeeded', 'failed', 'exhausted'])

const ruleConditionSchema = z.object({
  operator: comparisonOperatorSchema,
  value: z.number(),
  duration_seconds: z.number().int()
})

export const alertRuleDefinitionSchema = z.object({
  field: z.string(),
  trigger: ruleConditionSchema,
  clear: ruleConditionSchema,
  max_sample_gap_seconds: z.number().int(),
  severity: alertSeveritySchema
})

export const alertRuleRevisionSchema = z.object({
  id: z.string().uuid(),
  alert_rule_id: z.string().uuid(),
  revision: z.number().int().positive(),
  definition: alertRuleDefinitionSchema,
  created_by: z.string().uuid().nullable(),
  created_at: z.string().nullable()
})

export const alertRuleSchema = z.object({
  id: z.string().uuid(),
  project_id: z.string().uuid(),
  device_profile_id: z.string().uuid(),
  code: z.string(),
  name: z.string(),
  status: alertRuleStatusSchema,
  current_revision: alertRuleRevisionSchema.nullable(),
  revisions: z.array(alertRuleRevisionSchema).optional(),
  archived_at: z.string().nullable(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable()
})

export const alertEventSchema = z.object({
  id: z.string().uuid(),
  type: alertEventTypeSchema,
  occurred_at: z.string(),
  actor_id: z.string().uuid().nullable(),
  alert_rule_revision_id: z.string().uuid(),
  sample_id: z.string().uuid().nullable(),
  message_id: z.string().nullable(),
  data: z.record(z.string(), z.unknown())
})

export const alertSchema = z.object({
  id: z.string().uuid(),
  project_id: z.string().uuid(),
  device_id: z.string().uuid(),
  equipment_asset_id: z.string().uuid().nullable(),
  alert_rule_id: z.string().uuid(),
  alert_rule_revision_id: z.string().uuid(),
  device_profile_version_id: z.string().uuid().nullable(),
  severity: alertSeveritySchema,
  opened_at: z.string(),
  last_seen_at: z.string(),
  cleared_at: z.string().nullable(),
  clear_reason: alertClearReasonSchema.nullable(),
  acknowledged_at: z.string().nullable(),
  acknowledged_by: z.string().uuid().nullable(),
  open_sample_id: z.string().uuid().nullable(),
  open_message_id: z.string().nullable(),
  clear_sample_id: z.string().uuid().nullable(),
  clear_message_id: z.string().nullable(),
  evidence: z.record(z.string(), z.unknown()),
  events: z.array(alertEventSchema).optional()
})

export const webhookEndpointSchema = z.object({
  id: z.string().uuid(),
  project_id: z.string().uuid(),
  name: z.string(),
  url: z.string(),
  event_types: z.array(webhookEventTypeSchema),
  enabled: z.boolean(),
  secret_rotated_at: z.string().nullable(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable()
})

export const webhookDeliveryAttemptSchema = z.object({
  attempt_no: z.number().int().positive(),
  started_at: z.string(),
  finished_at: z.string(),
  status_code: z.number().int().nullable(),
  error: z.string().nullable(),
  duration_ms: z.number().int()
})

export const webhookDeliverySchema = z.object({
  id: z.string().uuid(),
  webhook_endpoint_id: z.string().uuid(),
  outbox_event_id: z.string().uuid(),
  event_type: webhookEventTypeSchema.optional(),
  status: webhookDeliveryStatusSchema,
  attempts: z.number().int().nonnegative(),
  next_attempt_at: z.string(),
  last_status_code: z.number().int().nullable(),
  last_error: z.string().nullable(),
  delivered_at: z.string().nullable(),
  attempt_history: z.array(webhookDeliveryAttemptSchema).optional(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable()
})

const cursorMetaSchema = z.object({ next_cursor: z.string().nullable() })

export const alertRulesResponseSchema = z.object({ data: z.array(alertRuleSchema) })
export const alertRuleResponseSchema = z.object({ data: alertRuleSchema })
export const alertsResponseSchema = z.object({ data: z.array(alertSchema), meta: cursorMetaSchema })
export const alertResponseSchema = z.object({ data: alertSchema })
export const webhookEndpointsResponseSchema = z.object({ data: z.array(webhookEndpointSchema) })
export const webhookEndpointResponseSchema = z.object({ data: webhookEndpointSchema })
// Create and rotate are the only responses that carry the plaintext secret.
export const webhookEndpointSecretResponseSchema = z.object({ data: webhookEndpointSchema, secret: z.string() })
export const webhookDeliveriesResponseSchema = z.object({ data: z.array(webhookDeliverySchema), meta: cursorMetaSchema })
export const webhookDeliveryResponseSchema = z.object({ data: webhookDeliverySchema })

export type ComparisonOperator = z.infer<typeof comparisonOperatorSchema>
export type AlertSeverity = z.infer<typeof alertSeveritySchema>
export type AlertRuleDefinition = z.infer<typeof alertRuleDefinitionSchema>
export type AlertRule = z.infer<typeof alertRuleSchema>
export type AlertRuleRevision = z.infer<typeof alertRuleRevisionSchema>
export type Alert = z.infer<typeof alertSchema>
export type AlertEvent = z.infer<typeof alertEventSchema>
export type WebhookEventType = z.infer<typeof webhookEventTypeSchema>
export type WebhookEndpoint = z.infer<typeof webhookEndpointSchema>
export type WebhookDelivery = z.infer<typeof webhookDeliverySchema>
export type WebhookDeliveryStatus = z.infer<typeof webhookDeliveryStatusSchema>

export interface AlertRuleInput {
  code: string
  name: string
  device_profile_id: string
  definition: AlertRuleDefinition
}

export interface WebhookEndpointInput {
  name: string
  url: string
  event_types: WebhookEventType[]
  enabled: boolean
}

export interface AlertFilters {
  state: 'active' | 'cleared' | null
  deviceId?: string
}
