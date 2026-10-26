import assert from 'node:assert/strict'
import test from 'node:test'
import { deviceApprovalSchema } from '../app/types/deviceApproval.ts'
import { deviceMqttConnectionSchema } from '../app/types/deviceCredential.ts'

const topics = {
  telemetry: 'liteboard/project/devices/device/telemetry',
  heartbeat: 'liteboard/project/devices/device/heartbeat',
  commands: 'liteboard/project/devices/device/commands',
  commands_ack: 'liteboard/project/devices/device/commands/ack'
}

test('device credential contract lists a TLS endpoint and the plaintext playground endpoint', () => {
  const parsed = deviceMqttConnectionSchema.safeParse({
    host: 'mqtt.liteboard.test',
    endpoints: [{ port: 8883, tls: true }, { port: 1883, tls: false }],
    topics
  })

  assert.equal(parsed.success, true)
})

test('device credential contract accepts a TLS-only endpoint list', () => {
  const parsed = deviceMqttConnectionSchema.safeParse({
    host: 'mqtt.liteboard.test',
    endpoints: [{ port: 8883, tls: true }],
    topics
  })

  assert.equal(parsed.success, true)
})

test('device credential contract rejects endpoints without TLS and empty endpoint lists', () => {
  const plaintextOnly = deviceMqttConnectionSchema.safeParse({
    host: 'mqtt.liteboard.test',
    endpoints: [{ port: 1883, tls: false }],
    topics
  })
  const empty = deviceMqttConnectionSchema.safeParse({
    host: 'mqtt.liteboard.test',
    endpoints: [],
    topics
  })

  assert.equal(plaintextOnly.success, false)
  assert.equal(empty.success, false)
})

test('device credential contract rejects the retired port, tls and plaintext_port fields as the only source', () => {
  const parsed = deviceMqttConnectionSchema.safeParse({
    host: 'mqtt.liteboard.test',
    port: 8883,
    tls: true,
    plaintext_port: 1883,
    topics
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
