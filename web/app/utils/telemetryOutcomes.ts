import type { TelemetryIngestOutcome } from '~/types/telemetry'

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
