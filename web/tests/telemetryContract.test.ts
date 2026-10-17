import assert from 'node:assert/strict'
import test from 'node:test'
import {
  telemetryHistoryResponseSchema,
  telemetryLatestResponseSchema,
  telemetryIngestStatusSchema,
  telemetryStreamEventSchema,
  telemetryTraceResponseSchema,
  telemetryUnresolvedMessageResponseSchema
} from '../app/types/telemetry.ts'
import type { TelemetryIngestOutcome, TelemetrySample } from '../app/types/telemetry.ts'
import { hasValidationErrors, mergeTelemetryOutcomes } from '../app/utils/telemetryOutcomes.ts'
import { applySampleToLatestValues, mergeTelemetrySamples } from '../app/utils/telemetrySamples.ts'

function sample(id: string, occurredAt: string, receivedAt = occurredAt): TelemetrySample {
  return {
    id,
    project_id: '00000000-0000-4000-8000-0000000000aa',
    device_id: '00000000-0000-4000-8000-0000000000bb',
    device_profile_version_id: '00000000-0000-4000-8000-0000000000cc',
    message_id: `message-${id.slice(-1)}`,
    occurred_at: occurredAt,
    received_at: receivedAt,
    values: { temperature: 40 }
  }
}

const first = sample('00000000-0000-4000-8000-000000000001', '2026-10-13T10:00:00.000000Z')
const second = sample('00000000-0000-4000-8000-000000000002', '2026-10-13T10:00:01.000000Z')
const late = sample('00000000-0000-4000-8000-000000000003', '2026-10-13T09:59:00.000000Z', '2026-10-13T10:00:05.000000Z')

test('stream events share the REST sample contract', () => {
  assert.equal(telemetryStreamEventSchema.safeParse({ type: 'telemetry.sample', data: first }).success, true)
  assert.equal(telemetryStreamEventSchema.safeParse({ type: 'telemetry.sample', data: { ...first, values: { raw: {} } } }).success, true)
  const profileless = { ...first, device_profile_version_id: null }
  assert.equal(telemetryStreamEventSchema.safeParse({ type: 'telemetry.sample', data: profileless }).success, true)
  assert.equal(telemetryHistoryResponseSchema.safeParse({ data: [profileless], meta: { next_cursor: null } }).success, true)
  assert.equal(telemetryTraceResponseSchema.safeParse({ data: { outcomes: [], sample: profileless } }).success, true)
})

test('stream events accept device-scoped ingest outcomes with the REST outcome contract', () => {
  const outcome = {
    id: '00000000-0000-4000-8000-000000000010',
    project_id: first.project_id,
    device_id: first.device_id,
    processing_id: '00000000-0000-4000-8000-000000000011',
    message_id: null,
    status: 'skipped',
    reason_codes: [{ code: 'schema_not_assigned', field: null }],
    stages: [
      { stage: 'received', at: '2026-10-13T10:00:00.000000Z' },
      { stage: 'skipped', at: '2026-10-13T10:00:00.000000Z' },
      { stage: 'commit', at: '2026-10-13T10:00:00.000000Z' }
    ],
    received_at: '2026-10-13T10:00:00.000000Z'
  }

  assert.equal(telemetryStreamEventSchema.safeParse({ type: 'telemetry.outcome', data: outcome }).success, true)
  assert.equal(telemetryStreamEventSchema.safeParse({ type: 'telemetry.outcome', data: { ...outcome, status: 'unknown' } }).success, false)
})

test('merging REST and stream outcomes deduplicates by processing id and keeps newest first', () => {
  const older: TelemetryIngestOutcome = {
    id: '00000000-0000-4000-8000-000000000010',
    project_id: first.project_id,
    device_id: first.device_id,
    processing_id: '00000000-0000-4000-8000-000000000011',
    message_id: null,
    status: 'skipped',
    reason_codes: [{ code: 'schema_not_assigned', field: null }],
    stages: [],
    received_at: '2026-10-13T10:00:00.000000Z'
  }
  const newest: TelemetryIngestOutcome = {
    ...older,
    id: '00000000-0000-4000-8000-000000000012',
    processing_id: '00000000-0000-4000-8000-000000000013',
    received_at: '2026-10-13T10:00:01.000000Z'
  }
  const recovered = { ...older, stages: [{ stage: 'broadcast', at: '2026-10-13T10:00:00.000000Z', subscribers: 1 }] }
  const merged = mergeTelemetryOutcomes([older], [recovered, newest], 10)

  assert.deepEqual(merged.map(outcome => outcome.processing_id), [newest.processing_id, older.processing_id])
  assert.equal(merged[1].stages[0].stage, 'broadcast')
})

test('schema_not_assigned on a skipped outcome is not shown as a validation error', () => {
  const outcome: TelemetryIngestOutcome = {
    id: '00000000-0000-4000-8000-000000000010',
    project_id: first.project_id,
    device_id: first.device_id,
    processing_id: '00000000-0000-4000-8000-000000000011',
    message_id: null,
    status: 'skipped',
    reason_codes: [{ code: 'schema_not_assigned', field: null }],
    stages: [],
    received_at: '2026-10-13T10:00:00.000000Z'
  }

  assert.equal(hasValidationErrors(outcome), false)
  assert.equal(hasValidationErrors({ ...outcome, status: 'rejected' }), true)
  assert.equal(hasValidationErrors({ ...outcome, reason_codes: [{ code: 'missing_required_field', field: 'message_id' }] }), true)
})

test('trace contract accepts a malformed message without message id or sample', () => {
  const parsed = telemetryTraceResponseSchema.safeParse({
    data: {
      outcomes: [{
        id: '00000000-0000-4000-8000-000000000010',
        project_id: first.project_id,
        device_id: first.device_id,
        processing_id: '00000000-0000-4000-8000-000000000011',
        message_id: null,
        status: 'rejected',
        reason_codes: [{ code: 'invalid_payload', field: null }],
        stages: [{ stage: 'received', at: '2026-10-13T10:00:00+00:00' }, { stage: 'broadcast', at: '2026-10-13T10:00:00+00:00', subscribers: 2 }],
        received_at: '2026-10-13T10:00:00.000000Z'
      }],
      sample: null
    }
  })

  assert.equal(parsed.success, true)
})

test('ingest status contract accepts skipped messages without an assigned profile', () => {
  assert.equal(telemetryIngestStatusSchema.safeParse('skipped').success, true)
})

test('merging history and realtime deduplicates by sample id and orders by event time', () => {
  const merged = mergeTelemetrySamples([second, first], [first, late, second], 10)

  assert.deepEqual(merged.map(item => item.id), [late.id, first.id, second.id])
})

test('merging keeps only the newest samples within the limit', () => {
  const merged = mergeTelemetrySamples([first, second], [late], 2)

  assert.deepEqual(merged.map(item => item.id), [first.id, second.id])
})

test('latest contract is one entry per metric', () => {
  const parsed = telemetryLatestResponseSchema.safeParse({
    data: [{
      metric: 'temperature',
      value: 40,
      sample_id: first.id,
      message_id: first.message_id,
      occurred_at: first.occurred_at,
      received_at: first.received_at
    }]
  })

  assert.equal(parsed.success, true)
})

test('unresolved message contract only accepts central reasons', () => {
  const message = {
    processing_id: '00000000-0000-4000-8000-000000000020',
    topic: 'liteboard/x/devices/y/telemetry',
    reason: 'invalid_topic',
    payload_size: 12,
    stages: [{ stage: 'received', at: '2026-10-13T10:00:00+00:00' }],
    received_at: '2026-10-13T10:00:00.000000Z'
  }

  assert.equal(telemetryUnresolvedMessageResponseSchema.safeParse({ data: message }).success, true)
  assert.equal(telemetryUnresolvedMessageResponseSchema.safeParse({ data: { ...message, reason: 'device_not_approved' } }).success, false)
})

test('latest values keep a metric the newest sample omits and ignore late samples', () => {
  const withRpm = { ...first, values: { temperature: 40, rpm: 1200 } }
  const newestWithoutRpm = { ...second, values: { temperature: 50 } }
  const lateSample = { ...late, values: { temperature: 1, rpm: 1 } }

  const latest = [withRpm, newestWithoutRpm, lateSample].reduce(applySampleToLatestValues, [])

  assert.deepEqual(
    latest.map(value => [value.metric, value.value, value.sample_id]),
    [['rpm', 1200, first.id], ['temperature', 50, second.id]]
  )
})
