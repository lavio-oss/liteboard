import { ApiError } from '~/composables/useApi'
import { useTelemetry } from '~/composables/useTelemetry'
import type { TelemetryDeviceScope } from '~/composables/useTelemetry'
import { telemetryStreamEventSchema } from '~/types/telemetry'
import type { TelemetryIngestOutcome, TelemetrySample } from '~/types/telemetry'
import type { RealtimeStreamStatus } from '~/utils/realtimeStatus'

export type TelemetryStreamStatus = RealtimeStreamStatus

interface TelemetryStreamHandlers {
  onSample: (sample: TelemetrySample) => void
  onOutcome: (outcome: TelemetryIngestOutcome) => void
  /** Called on every (re)connect; fetch the gap over REST because events sent while disconnected are lost. */
  onLive: (tenantId: string) => Promise<void>
}

const RENEW_INTERVAL_MS = 40_000
const MAX_BACKOFF_MS = 30_000

export function useTelemetryStream(
  scope: () => TelemetryDeviceScope,
  handlers: TelemetryStreamHandlers,
  tenantId: () => string | null
) {
  const config = useRuntimeConfig()
  const { issueStreamTicket } = useTelemetry()
  const status = ref<TelemetryStreamStatus>('connecting')
  let socket: WebSocket | null = null
  let renewTimer: ReturnType<typeof setInterval> | undefined
  let reconnectTimer: ReturnType<typeof setTimeout> | undefined
  let attempts = 0
  let generation = 0
  let started = false

  function isAccessDenied(error: unknown): boolean {
    return error instanceof ApiError && [401, 403, 404].includes(error.status)
  }

  function clearSocket(): void {
    clearInterval(renewTimer)
    clearTimeout(reconnectTimer)
    renewTimer = undefined
    reconnectTimer = undefined
    const currentSocket = socket
    socket = null
    currentSocket?.close(1000)
  }

  function scheduleReconnect(scopeTenantId: string, currentGeneration: number): void {
    if (!started || generation !== currentGeneration) {
      return
    }

    status.value = 'reconnecting'
    reconnectTimer = setTimeout(
      () => void connect(scopeTenantId, currentGeneration),
      Math.min(1000 * 2 ** attempts, MAX_BACKOFF_MS)
    )
    attempts += 1
  }

  async function renew(ws: WebSocket, scopeTenantId: string, currentGeneration: number): Promise<void> {
    try {
      const currentScope = scope()
      if (currentScope.tenantId !== scopeTenantId) {
        return
      }
      const ticket = await issueStreamTicket(currentScope)

      if (generation === currentGeneration && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ type: 'renew', ticket }))
      }
    } catch (error: unknown) {
      if (isAccessDenied(error)) {
        if (generation === currentGeneration) {
          status.value = 'forbidden'
          ws.close()
        }
      }
    }
  }

  async function connect(scopeTenantId: string, currentGeneration: number): Promise<void> {
    let ticket: string

    try {
      if (tenantId() !== scopeTenantId) {
        return
      }
      const currentScope = scope()
      if (currentScope.tenantId !== scopeTenantId) {
        return
      }
      ticket = await issueStreamTicket(currentScope)
    } catch (error: unknown) {
      if (generation !== currentGeneration || !started) {
        return
      }
      if (isAccessDenied(error)) {
        status.value = 'forbidden'
      } else {
        scheduleReconnect(scopeTenantId, currentGeneration)
      }
      return
    }

    if (!started || generation !== currentGeneration || tenantId() !== scopeTenantId) {
      return
    }

    const ws = new WebSocket(`${config.public.telemetryWsBase}/ws/telemetry?ticket=${encodeURIComponent(ticket)}`)
    socket = ws

    ws.onopen = () => {
      if (!started || generation !== currentGeneration) {
        ws.close(1000)
        return
      }

      attempts = 0
      status.value = 'live'
      renewTimer = setInterval(() => void renew(ws, scopeTenantId, currentGeneration), RENEW_INTERVAL_MS)
      void handlers.onLive(scopeTenantId)
    }

    ws.onmessage = (message: MessageEvent<string>) => {
      if (generation !== currentGeneration) {
        return
      }

      const event = telemetryStreamEventSchema.parse(JSON.parse(message.data) as unknown)

      if (event.type === 'telemetry.sample') {
        handlers.onSample(event.data)
      } else {
        handlers.onOutcome(event.data)
      }
    }

    ws.onclose = () => {
      clearInterval(renewTimer)
      renewTimer = undefined
      if (socket === ws) {
        socket = null
      }

      if (started && generation === currentGeneration && status.value !== 'forbidden') {
        scheduleReconnect(scopeTenantId, currentGeneration)
      }
    }
  }

  watch(tenantId, (scopeTenantId) => {
    if (!started) {
      return
    }

    generation += 1
    clearSocket()
    attempts = 0

    if (scopeTenantId === null) {
      status.value = 'stopped'
      return
    }

    status.value = 'connecting'
    void connect(scopeTenantId, generation)
  }, { flush: 'sync' })

  function start(): void {
    started = true
    generation += 1
    attempts = 0
    const currentTenantId = tenantId()

    if (currentTenantId === null) {
      status.value = 'stopped'
      return
    }

    status.value = 'connecting'
    void connect(currentTenantId, generation)
  }

  function stop(): void {
    started = false
    generation += 1
    status.value = 'stopped'
    clearSocket()
  }

  onScopeDispose(stop)

  return { start, status, stop }
}
