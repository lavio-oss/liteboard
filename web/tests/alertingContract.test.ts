import assert from 'node:assert/strict'
import test from 'node:test'
import {
  alertResponseSchema,
  alertRuleResponseSchema,
  alertsResponseSchema,
  webhookDeliveriesResponseSchema,
  webhookEndpointSecretResponseSchema,
  webhookEndpointsResponseSchema
} from '../app/types/alerting.ts'
import type { AlertRuleDefinition } from '../app/types/alerting.ts'
import { clearOperatorsFor, describeAlertRule, validateAlertRuleDefinition } from '../app/utils/alertRuleDefinition.ts'

const ids = {
  project: '00000000-0000-4000-8000-0000000000aa',
  rule: '00000000-0000-4000-8000-0000000000bb',
  revision: '00000000-0000-4000-8000-0000000000cc',
  device: '00000000-0000-4000-8000-0000000000dd',
  alert: '00000000-0000-4000-8000-0000000000ee',
  endpoint: '00000000-0000-4000-8000-0000000000ff',
  profile: '00000000-0000-4000-8000-000000000011',
  event: '00000000-0000-4000-8000-000000000012'
}
const at = '2026-10-18T08:00:00.000000Z'

const definition: AlertRuleDefinition = {
  field: 'temperature',
  trigger: { operator: 'gt', value: 80, duration_seconds: 30 },
  clear: { operator: 'lte', value: 75, duration_seconds: 30 },
  max_sample_gap_seconds: 15,
  severity: 'critical'
}

test('alert rule responses match the AlertRuleResource shape', () => {
  const revision = { id: ids.revision, alert_rule_id: ids.rule, revision: 1, definition, created_by: null, created_at: at }
  const rule = {
    id: ids.rule, project_id: ids.project, device_profile_id: ids.profile, code: 'high_temperature', name: 'High temperature',
    status: 'active', current_revision: revision, revisions: [revision], archived_at: null, created_at: at, updated_at: at
  }

  assert.equal(alertRuleResponseSchema.safeParse({ data: rule }).success, true)
  assert.equal(alertRuleResponseSchema.safeParse({ data: { ...rule, status: 'paused' } }).success, false)
})

test('alert list and detail responses match the AlertResource shape', () => {
  const alert = {
    id: ids.alert, project_id: ids.project, device_id: ids.device, equipment_asset_id: null, alert_rule_id: ids.rule,
    alert_rule_revision_id: ids.revision, device_profile_version_id: null, severity: 'critical', opened_at: at, last_seen_at: at,
    cleared_at: null, clear_reason: null, acknowledged_at: null, acknowledged_by: null, open_sample_id: null,
    open_message_id: 'm-1', clear_sample_id: null, clear_message_id: null, evidence: { field: 'temperature', value: 85 }
  }
  const event = {
    id: ids.event, type: 'opened', occurred_at: at, actor_id: null, alert_rule_revision_id: ids.revision,
    sample_id: null, message_id: 'm-1', data: {}
  }

  assert.equal(alertsResponseSchema.safeParse({ data: [alert], meta: { next_cursor: null, per_page: 100 } }).success, true)
  assert.equal(alertResponseSchema.safeParse({ data: { ...alert, cleared_at: at, clear_reason: 'device_offline', events: [event] } }).success, true)
  assert.equal(alertResponseSchema.safeParse({ data: { ...alert, clear_reason: 'manual' } }).success, false)
})

test('webhook responses keep the secret outside data and only on create/rotate', () => {
  const endpoint = {
    id: ids.endpoint, project_id: ids.project, name: 'EAMO', url: 'https://hooks.example.com', event_types: ['alert.opened'],
    enabled: true, secret_rotated_at: null, created_at: at, updated_at: at
  }

  assert.equal(webhookEndpointSecretResponseSchema.safeParse({ data: endpoint, secret: 'whsec_x' }).success, true)
  assert.equal(webhookEndpointSecretResponseSchema.safeParse({ data: endpoint }).success, false)
  assert.equal('secret' in webhookEndpointsResponseSchema.parse({ data: [{ ...endpoint, secret: 'leak' }] }).data[0]!, false)

  const delivery = {
    id: ids.event, webhook_endpoint_id: ids.endpoint, outbox_event_id: ids.alert, event_type: 'alert.opened', status: 'exhausted',
    attempts: 8, next_attempt_at: at, last_status_code: 500, last_error: 'http_500', delivered_at: null,
    attempt_history: [{ attempt_no: 1, started_at: at, finished_at: at, status_code: 500, error: 'http_500', duration_ms: 12 }],
    created_at: at, updated_at: at
  }
  assert.equal(webhookDeliveriesResponseSchema.safeParse({ data: [delivery], meta: { next_cursor: null } }).success, true)
})

test('rule validation mirrors the API constraints', () => {
  assert.deepEqual(validateAlertRuleDefinition(definition), {})
  assert.deepEqual(clearOperatorsFor('gt'), ['lte', 'lt'])
  assert.deepEqual(clearOperatorsFor('lte'), ['gte', 'gt'])

  const cases: [Partial<AlertRuleDefinition>, string][] = [
    [{ field: '' }, 'field'],
    [{ clear: { ...definition.clear, operator: 'gte' } }, 'clear.operator'],
    [{ clear: { ...definition.clear, value: 81 } }, 'clear.value'],
    [{ trigger: { ...definition.trigger, operator: 'gte' }, clear: { ...definition.clear, value: 80 } }, 'clear.value'],
    [{ trigger: { ...definition.trigger, duration_seconds: 4 } }, 'trigger.duration_seconds'],
    [{ clear: { ...definition.clear, duration_seconds: 3601 } }, 'clear.duration_seconds'],
    [{ max_sample_gap_seconds: 4 }, 'max_sample_gap_seconds'],
    [{ max_sample_gap_seconds: 31 }, 'max_sample_gap_seconds'],
    [{ trigger: { ...definition.trigger, value: Number.NaN } }, 'trigger.value']
  ]

  for (const [override, key] of cases) {
    assert.ok(key in validateAlertRuleDefinition({ ...definition, ...override }), `expected an error on ${key}`)
  }

  // Touching boundaries are fine when one side is exclusive.
  assert.deepEqual(validateAlertRuleDefinition({ ...definition, clear: { ...definition.clear, value: 80 } }), {})
  assert.deepEqual(validateAlertRuleDefinition({
    ...definition,
    field: 'rpm',
    trigger: { operator: 'lt', value: 100, duration_seconds: 30 },
    clear: { operator: 'gte', value: 100, duration_seconds: 30 }
  }), {})
})

test('rules have a readable one-line summary', () => {
  assert.equal(describeAlertRule(definition), 'temperature > 80 for 30s · clear ≤ 75 for 30s')
})
