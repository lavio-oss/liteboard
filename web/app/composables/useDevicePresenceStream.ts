import { ApiError } from '~/composables/useApi'
import { useTelemetry } from '~/composables/useTelemetry'
import { devicePresenceStreamEventSchema } from '~/types/telemetry'
import type { DevicePresenceEventData } from '~/types/telemetry'
import type { RealtimeStreamStatus } from '~/utils/realtimeStatus'

interface DevicePresenceStreamHandlers {
  onPresence: (presence: DevicePresenceEventData) => void
  onLive: () => Promise<void>
}

const RENEW_INTERVAL_MS = 40_000
const MAX_BACKOFF_MS = 30_000

export function useDevicePresenceStream(tenantId: () => string | null, handlers: DevicePresenceStreamHandlers) {
  const config = useRuntimeConfig()
  const { issueDevicePresenceStreamTicket } = useTelemetry()
  const status = ref<RealtimeStreamStatus>('connecting')
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

  function scheduleReconnect(scopeId: string, currentGeneration: number): void {
    if (!started || generation !== currentGeneration) {
      return
    }

    status.value = 'reconnecting'
    reconnectTimer = setTimeout(() => void connect(scopeId, currentGeneration), Math.min(1000 * 2 ** attempts, MAX_BACKOFF_MS))
    attempts += 1
  }

  async function renew(ws: WebSocket, scopeId: string, currentGeneration: number): Promise<void> {
    try {
      const ticket = await issueDevicePresenceStreamTicket(scopeId)

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

  async function connect(scopeId: string, currentGeneration: number): Promise<void> {
    let ticket: string

    try {
      ticket = await issueDevicePresenceStreamTicket(scopeId)
    } catch (error: unknown) {
      if (generation !== currentGeneration || !started) {
        return
      }
      if (isAccessDenied(error)) {
        status.value = 'forbidden'
      } else {
        scheduleReconnect(scopeId, currentGeneration)
      }
      return
    }

    if (!started || generation !== currentGeneration || tenantId() !== scopeId) {
      return
    }

    const ws = new WebSocket(
      `${config.public.telemetryWsBase}/ws/device-presence?ticket=${encodeURIComponent(ticket)}`
    )
    socket = ws

    ws.onopen = () => {
      if (!started || generation !== currentGeneration) {
        ws.close(1000)
        return
      }

      attempts = 0
      status.value = 'live'
      renewTimer = setInterval(() => void renew(ws, scopeId, currentGeneration), RENEW_INTERVAL_MS)
      void handlers.onLive()
    }

    ws.onmessage = (message: MessageEvent<string>) => {
      if (generation !== currentGeneration) {
        return
      }

      const event = devicePresenceStreamEventSchema.parse(JSON.parse(message.data) as unknown)
      handlers.onPresence(event.data)
    }

    ws.onclose = () => {
      clearInterval(renewTimer)
      renewTimer = undefined
      if (socket === ws) {
        socket = null
      }

      if (started && generation === currentGeneration && status.value !== 'forbidden') {
        scheduleReconnect(scopeId, currentGeneration)
      }
    }
  }

  watch(tenantId, (scopeId) => {
    if (!started) {
      return
    }

    generation += 1
    clearSocket()
    attempts = 0

    if (scopeId === null) {
      status.value = 'stopped'
      return
    }

    status.value = 'connecting'
    void connect(scopeId, generation)
  }, { flush: 'sync' })

  function start(): void {
    started = true
    generation += 1
    attempts = 0
    const scopeId = tenantId()

    if (scopeId === null) {
      status.value = 'stopped'
      return
    }

    status.value = 'connecting'
    void connect(scopeId, generation)
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
