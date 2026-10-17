import type { Device } from '~/types/admin'
import type { DevicePresenceEventData } from '~/types/telemetry'

export function mergeDevicePresenceEvents(
  current: DevicePresenceEventData | null,
  incoming: DevicePresenceEventData
): DevicePresenceEventData {
  if (current === null) {
    return incoming
  }

  if (current.device_id !== incoming.device_id || current.project_id !== incoming.project_id) {
    throw new Error('Device presence events cannot be merged across devices or projects.')
  }

  return Date.parse(incoming.updated_at) > Date.parse(current.updated_at) ? incoming : current
}

export function applyDevicePresenceToDevice(device: Device, presence: DevicePresenceEventData | null): Device {
  if (presence === null) {
    return device
  }

  if (presence.device_id !== device.id || presence.project_id !== device.project_id) {
    throw new Error('Device presence does not match the device snapshot.')
  }

  if (Date.parse(presence.updated_at) <= Date.parse(device.updated_at)) {
    return device
  }

  return {
    ...device,
    connection_state: presence.connection_state,
    last_seen_at: presence.last_seen_at,
    updated_at: presence.updated_at
  }
}
