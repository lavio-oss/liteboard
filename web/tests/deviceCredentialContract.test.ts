import assert from 'node:assert/strict'
import test from 'node:test'
import { deviceApprovalSchema } from '../app/types/deviceApproval.ts'
import { deviceMqttConnectionSchema } from '../app/types/deviceCredential.ts'

test('device credential contract includes MQTT TLS connection settings and topics', () => {
  const parsed = deviceMqttConnectionSchema.safeParse({
    host: 'mqtt.liteboard.test',
    port: 8883,
    tls: true,
    topics: {
      telemetry: 'liteboard/project/devices/device/telemetry',
      heartbeat: 'liteboard/project/devices/device/heartbeat',
      commands: 'liteboard/project/devices/device/commands',
      commands_ack: 'liteboard/project/devices/device/commands/ack'
    }
  })

  assert.equal(parsed.success, true)
})

test('device credential contract rejects a non-TLS MQTT port', () => {
  const parsed = deviceMqttConnectionSchema.safeParse({
    host: 'mqtt.liteboard.test',
    port: 1883,
    tls: false,
    topics: {
      telemetry: 'telemetry',
      heartbeat: 'heartbeat',
      commands: 'commands',
      commands_ack: 'commands_ack'
    }
  })

  assert.equal(parsed.success, false)
})

test('device contract distinguishes approval from operational status and carries rejection reason', () => {
  const parsed = deviceApprovalSchema.safeParse({
    approval_status: 'rejected',
    approval_reason: 'Device identity could not be verified.'
  })

  assert.equal(parsed.success, true)
})
