import assert from 'node:assert/strict'
import test from 'node:test'
import type { TelemetryIngestOutcome } from '../app/types/telemetry.ts'
import { summarizeOutcomes } from '../app/utils/telemetryOutcomes.ts'

function outcome(
  status: TelemetryIngestOutcome['status'],
  receivedAt: string,
  codes: string[] = []
): TelemetryIngestOutcome {
  return {
    status,
    received_at: receivedAt,
    reason_codes: codes.map(code => ({ code, field: null }))
  } as TelemetryIngestOutcome
}

test('summarizes an empty list without dividing by zero', () => {
  const summary = summarizeOutcomes([])

  assert.equal(summary.successRate, null)
  assert.equal(summary.averageGapSeconds, null)
  assert.deepEqual(summary.recentGaps, [])
  assert.deepEqual(summary.topReasons, [])
})

test('summarizes success rate, top reasons and average gap', () => {
  const summary = summarizeOutcomes([
    outcome('persisted', '2026-10-11T00:00:00Z'),
    outcome('rejected', '2026-10-11T00:00:10Z', ['invalid_type', 'below_minimum']),
    outcome('rejected', '2026-10-11T00:00:30Z', ['invalid_type']),
    outcome('persisted', '2026-10-11T00:00:40Z')
  ])

  assert.equal(summary.persistedCount, 2)
  assert.deepEqual(summary.recentGaps.map(gap => gap.seconds), [10, 20, 10])
  assert.equal(summary.successRate, 50)
  assert.deepEqual(summary.topReasons, [{ code: 'invalid_type', count: 2 }, { code: 'below_minimum', count: 1 }])
  assert.equal(summary.averageGapSeconds, 40 / 3)
})
