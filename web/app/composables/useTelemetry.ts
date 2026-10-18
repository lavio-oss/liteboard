import { useApi } from '~/composables/useApi'
import {
  telemetryAggregatesResponseSchema,
  telemetryHistoryResponseSchema,
  telemetryLatestResponseSchema,
  telemetryOutcomesResponseSchema,
  telemetryStreamTicketResponseSchema,
  telemetryTraceResponseSchema,
  devicePresenceStreamTicketResponseSchema
} from '~/types/telemetry'
import type {
  TelemetryAggregate,
  TelemetryIngestOutcome,
  TelemetryInterval,
  TelemetryLatestValue,
  TelemetrySample,
  TelemetryTrace
} from '~/types/telemetry'

export interface TelemetryDeviceScope {
  tenantId: string
  projectId: string
  deviceId: string
}

export interface TelemetryRange {
  from: string
  to: string
  metrics: string[]
}

function devicePath(scope: TelemetryDeviceScope): string {
  return `/api/tenants/${scope.tenantId}/projects/${scope.projectId}/devices/${scope.deviceId}`
}

function rangeQuery(range: TelemetryRange): URLSearchParams {
  const query = new URLSearchParams({ from: range.from, to: range.to })

  for (const metric of range.metrics) {
    query.append('metrics[]', metric)
  }

  return query
}

export function useTelemetry() {
  const api = useApi()

  async function loadLatestTelemetry(scope: TelemetryDeviceScope): Promise<TelemetryLatestValue[]> {
    const response = await api.request(`${devicePath(scope)}/telemetry/latest`, telemetryLatestResponseSchema)

    return response.data
  }

  async function loadTelemetryHistory(
    scope: TelemetryDeviceScope,
    range: TelemetryRange,
    limit: number,
    cursor: string | null
  ): Promise<{ samples: TelemetrySample[], nextCursor: string | null }> {
    const query = rangeQuery(range)
    query.set('limit', String(limit))

    if (cursor !== null) {
      query.set('cursor', cursor)
    }

    const response = await api.request(`${devicePath(scope)}/telemetry?${query}`, telemetryHistoryResponseSchema)

    return { samples: response.data, nextCursor: response.meta.next_cursor }
  }

  async function loadTelemetryAggregates(
    scope: TelemetryDeviceScope,
    range: TelemetryRange,
    interval: TelemetryInterval
  ): Promise<TelemetryAggregate[]> {
    const query = rangeQuery(range)
    query.set('interval', interval)

    const response = await api.request(`${devicePath(scope)}/telemetry/aggregates?${query}`, telemetryAggregatesResponseSchema)

    return response.data
  }

  async function loadMessageOutcomes(scope: TelemetryDeviceScope, limit: number): Promise<TelemetryIngestOutcome[]> {
    const response = await api.request(`${devicePath(scope)}/messages?limit=${limit}`, telemetryOutcomesResponseSchema)

    return response.data
  }

  async function loadMessageTrace(scope: TelemetryDeviceScope, message: string): Promise<TelemetryTrace> {
    const response = await api.request(
      `${devicePath(scope)}/messages/${encodeURIComponent(message)}/trace`,
      telemetryTraceResponseSchema
    )

    return response.data
  }

  async function issueStreamTicket(scope: TelemetryDeviceScope): Promise<string> {
    const response = await api.request(
      `${devicePath(scope)}/telemetry/stream-ticket`,
      telemetryStreamTicketResponseSchema,
      { method: 'POST' }
    )

    return response.data.ticket
  }

  async function issueDevicePresenceStreamTicket(tenantId: string): Promise<string> {
    const response = await api.request(
      `/api/tenants/${tenantId}/devices/presence-stream-ticket`,
      devicePresenceStreamTicketResponseSchema,
      { method: 'POST' }
    )

    return response.data.ticket
  }

  return {
    issueStreamTicket,
    issueDevicePresenceStreamTicket,
    loadLatestTelemetry,
    loadMessageOutcomes,
    loadMessageTrace,
    loadTelemetryAggregates,
    loadTelemetryHistory
  }
}
