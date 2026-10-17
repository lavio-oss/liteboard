import type { TelemetryLatestValue, TelemetrySample } from '~/types/telemetry'

// Timestamps share one fixed-width UTC ISO format across REST and WebSocket, so string order is time order.
function compareSamples(left: TelemetrySample, right: TelemetrySample): number {
  return left.occurred_at.localeCompare(right.occurred_at)
    || left.received_at.localeCompare(right.received_at)
    || left.id.localeCompare(right.id)
}

/**
 * Merges REST history and realtime samples by sample id, ordered by event time,
 * keeping only the newest `limit` samples so live views stay bounded.
 */
export function mergeTelemetrySamples(
  current: TelemetrySample[],
  incoming: TelemetrySample[],
  limit: number
): TelemetrySample[] {
  const byId = new Map(current.map(sample => [sample.id, sample]))

  for (const sample of incoming) {
    byId.set(sample.id, sample)
  }

  return [...byId.values()].sort(compareSamples).slice(-limit)
}

/**
 * Applies one sample to the per-metric latest values with the same rule as the data plane:
 * a metric only moves forward in (occurred_at, received_at, sample id).
 */
export function applySampleToLatestValues(
  values: TelemetryLatestValue[],
  sample: TelemetrySample
): TelemetryLatestValue[] {
  const byMetric = new Map(values.map(value => [value.metric, value]))

  for (const [metric, value] of Object.entries(sample.values)) {
    const current = byMetric.get(metric)
    const isNewer = current === undefined
      || (sample.occurred_at.localeCompare(current.occurred_at)
        || sample.received_at.localeCompare(current.received_at)
        || sample.id.localeCompare(current.sample_id)) > 0

    if (isNewer) {
      byMetric.set(metric, {
        metric,
        value,
        sample_id: sample.id,
        message_id: sample.message_id,
        occurred_at: sample.occurred_at,
        received_at: sample.received_at
      })
    }
  }

  return [...byMetric.values()].sort((left, right) => left.metric.localeCompare(right.metric))
}
