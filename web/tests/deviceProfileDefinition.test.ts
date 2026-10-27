import assert from 'node:assert/strict'
import test from 'node:test'
import {
  deviceProfileVersionDefinitionSchema,
  deviceProfileVersionInputDefinitionSchema
} from '../app/types/deviceProfileDefinition.ts'

const motorV1 = {
  strict_mode: true,
  fields: {
    temperature: { type: 'float', unit: 'celsius', required: true, min: -20, max: 150 },
    vibration: { type: 'float', unit: 'mm/s RMS', required: true },
    rpm: { type: 'integer', unit: 'rpm', required: true, min: 0 }
  }
}

function issuePaths(definition: unknown): string[] {
  const result = deviceProfileVersionDefinitionSchema.safeParse(definition)

  assert.equal(result.success, false)

  return result.error.issues.map(issue => issue.path.join('.'))
}

test('device profile definition accepts the motor-v1 schema from the spec', () => {
  assert.equal(deviceProfileVersionDefinitionSchema.safeParse(motorV1).success, true)
})

test('device profile definition rejects a minimum greater than the maximum', () => {
  assert.deepEqual(issuePaths({
    strict_mode: true,
    fields: { temperature: { type: 'float', unit: 'celsius', required: true, min: 10, max: 0 } }
  }), ['fields.temperature.max'])
})

test('device profile definition rejects ranges on non-numeric fields', () => {
  assert.deepEqual(issuePaths({
    strict_mode: true,
    fields: { label: { type: 'string', unit: 'text', required: true, min: 1 } }
  }), ['fields.label.min'])
})

test('device profile definition only allows unique allowed values on string fields', () => {
  assert.deepEqual(issuePaths({
    strict_mode: true,
    fields: { temperature: { type: 'float', unit: 'celsius', required: true, allowed_values: ['hot'] } }
  }), ['fields.temperature.allowed_values'])

  assert.deepEqual(issuePaths({
    strict_mode: true,
    fields: { state: { type: 'string', unit: 'code', required: true, allowed_values: ['running', 'running'] } }
  }), ['fields.state.allowed_values.1'])
})

test('device profile definition rejects field names the backend does not accept', () => {
  for (const fieldName of ['1temp', 'temp-c', '_temp']) {
    assert.deepEqual(issuePaths({
      strict_mode: true,
      fields: { [fieldName]: { type: 'float', unit: 'celsius', required: true } }
    }), [`fields.${fieldName}`])
  }
})

test('device profile definition accepts the empty auto-created schema but input requires a field', () => {
  const empty = { strict_mode: false, fields: {} }

  assert.equal(deviceProfileVersionDefinitionSchema.safeParse(empty).success, true)

  const input = deviceProfileVersionInputDefinitionSchema.safeParse(empty)

  assert.equal(input.success, false)
  assert.deepEqual(input.error.issues.map(issue => issue.path.join('.')), ['fields'])
})

test('device profile definition requires known keys', () => {
  assert.deepEqual(issuePaths({ ...motorV1, version: 2 }), [''])
  assert.deepEqual(issuePaths({
    strict_mode: true,
    fields: { temperature: { type: 'float', unit: 'celsius', required: true, precision: 2 } }
  }), ['fields.temperature'])
})
