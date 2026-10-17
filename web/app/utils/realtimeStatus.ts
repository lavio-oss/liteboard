import type { DeviceConnectionState } from '~/types/admin'
import type { TelemetryIngestStatus } from '~/types/telemetry'

export type RealtimeStreamStatus = 'connecting' | 'live' | 'reconnecting' | 'forbidden' | 'stopped'
export type RealtimeStatusColor = 'success' | 'warning' | 'error' | 'neutral'

export const deviceConnectionStatePresentation: Record<
  DeviceConnectionState,
  { label: string, color: RealtimeStatusColor, icon: string }
> = {
  online: { label: 'Online', color: 'success', icon: 'i-lucide-radio' },
  unstable: { label: 'Unstable', color: 'warning', icon: 'i-lucide-radio-tower' },
  offline: { label: 'Offline', color: 'neutral', icon: 'i-lucide-wifi-off' }
}

export const telemetryOutcomeStatusPresentation: Record<
  TelemetryIngestStatus,
  { label: string, color: RealtimeStatusColor, icon: string }
> = {
  persisted: { label: 'Persisted', color: 'success', icon: 'i-lucide-circle-check' },
  rejected: { label: 'Rejected', color: 'error', icon: 'i-lucide-circle-x' },
  duplicate: { label: 'Duplicate', color: 'neutral', icon: 'i-lucide-copy-check' },
  idempotency_conflict: { label: 'Idempotency conflict', color: 'warning', icon: 'i-lucide-triangle-alert' },
  unresolved: { label: 'Unresolved', color: 'warning', icon: 'i-lucide-circle-help' },
  skipped: { label: 'Skipped', color: 'neutral', icon: 'i-lucide-skip-forward' }
}
