import type { TelemetryIngestOutcome } from '~/types/telemetry'

export function summarizeOutcomes(outcomes: TelemetryIngestOutcome[]) {
  const reasonCounts = new Map<string, number>()
  for (const reason of outcomes.flatMap(outcome => outcome.reason_codes)) {
    reasonCounts.set(reason.code, (reasonCounts.get(reason.code) ?? 0) + 1)
  }

  const times = outcomes.map(outcome => Date.parse(outcome.received_at))
  const sortedTimes = times.toSorted((a, b) => a - b)

  const persistedCount = outcomes.filter(outcome => outcome.status === 'persisted').length

  return {
    persistedCount,
    successRate: outcomes.length === 0 ? null : Math.round(persistedCount / outcomes.length * 100),
    topReasons: [...reasonCounts]
      .map(([code, count]) => ({ code, count }))
      .sort((a, b) => b.count - a.count)
      .slice(0, 5),
    // Seconds between consecutive packets, oldest first.
    recentGaps: sortedTimes.slice(1).map((time, index) => ({ index, seconds: (time - sortedTimes[index]!) / 1000 })),
    averageGapSeconds: times.length < 2
      ? null
      : (Math.max(...times) - Math.min(...times)) / 1000 / (times.length - 1)
  }
}

export function mergeTelemetryOutcomes(
  existing: TelemetryIngestOutcome[],
  incoming: TelemetryIngestOutcome[],
  limit: number
): TelemetryIngestOutcome[] {
  const byProcessingId = new Map(existing.map(outcome => [outcome.processing_id, outcome]))

  for (const outcome of incoming) {
    byProcessingId.set(outcome.processing_id, outcome)
  }

  return [...byProcessingId.values()]
    .sort((left, right) => right.received_at.localeCompare(left.received_at)
      || right.processing_id.localeCompare(left.processing_id))
    .slice(0, limit)
}

export function hasValidationErrors(outcome: TelemetryIngestOutcome): boolean {
  return outcome.reason_codes.some(reason => !(
    outcome.status === 'skipped'
    && reason.code === 'schema_not_assigned'
    && reason.field === null
  ))
}
