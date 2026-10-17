import assert from 'node:assert/strict'
import test from 'node:test'
import type { Device } from '../app/types/admin.ts'
import {
  devicePresenceStreamEventSchema,
  telemetryIngestStatusSchema
} from '../app/types/telemetry.ts'
import { applyDevicePresenceToDevice, mergeDevicePresenceEvents } from '../app/utils/devicePresence.ts'
import {
  deviceConnectionStatePresentation,
  telemetryOutcomeStatusPresentation
} from '../app/utils/realtimeStatus.ts'

const device: Device = {
  id: '00000000-0000-4000-8000-0000000000bb',
  project_id: '00000000-0000-4000-8000-0000000000aa',
  name: 'Device A',
  code: 'device-a',
  status: 'active',
  approval_status: 'approved',
  approval_reason: null,
  description: null,
  connection_state: 'offline',
  credential_type: 'access_token',
  credential_status: 'active',
  mqtt_topics: {
    telemetry: 'telemetry',
    heartbeat: 'heartbeat',
    commands: 'commands',
    commands_ack: 'commands_ack'
  },
  device_profile_version_id: null,
  equipment_asset_id: null,
  last_seen_at: null,
  created_at: '2026-10-15T10:00:00.000Z',
  updated_at: '2026-10-15T10:00:00.000Z'
}

const presence = {
  project_id: device.project_id,
  device_id: device.id,
  connection_state: 'online',
  last_seen_at: '2026-10-15T10:00:01.000000Z',
  updated_at: '2026-10-15T10:00:01.000000Z'
}

test('device presence stream events validate their tenant-owned device data contract', () => {
  assert.equal(devicePresenceStreamEventSchema.safeParse({ type: 'device.presence', data: presence }).success, true)
  assert.equal(devicePresenceStreamEventSchema.safeParse({
    type: 'device.presence',
    data: { ...presence, connection_state: 'rejected' }
  }).success, false)
  assert.equal(devicePresenceStreamEventSchema.safeParse({
    type: 'telemetry.outcome',
    data: presence
  }).success, false)
})

test('a newer presence event overrides an older REST device snapshot', () => {
  const current = devicePresenceStreamEventSchema.parse({ type: 'device.presence', data: presence }).data
  const updated = applyDevicePresenceToDevice(device, current)

  assert.equal(updated.connection_state, 'online')
  assert.equal(updated.last_seen_at, presence.last_seen_at)
  assert.equal(updated.updated_at, presence.updated_at)
})

test('an older event cannot overwrite a newer REST snapshot or presence event', () => {
  const current = devicePresenceStreamEventSchema.parse({ type: 'device.presence', data: presence }).data
  const staleEvent = { ...current, connection_state: 'unstable' as const, updated_at: '2026-10-15T10:00:00.500000Z' }
  const newest = mergeDevicePresenceEvents(current, staleEvent)
  const newerSnapshot: Device = { ...device, connection_state: 'unstable', updated_at: '2026-10-15T10:00:02.000Z' }

  assert.equal(newest, current)
  assert.equal(applyDevicePresenceToDevice(newerSnapshot, current), newerSnapshot)
})

test('device and ingest status presentations stay complete', () => {
  assert.deepEqual(Object.keys(deviceConnectionStatePresentation).sort(), ['offline', 'online', 'unstable'])
  assert.deepEqual(Object.keys(telemetryOutcomeStatusPresentation).sort(), telemetryIngestStatusSchema.options.slice().sort())
  assert.equal(deviceConnectionStatePresentation.online.label, 'Online')
  assert.equal(telemetryOutcomeStatusPresentation.rejected.label, 'Rejected')
})
