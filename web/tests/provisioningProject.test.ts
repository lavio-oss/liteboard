import assert from 'node:assert/strict'
import test from 'node:test'
import { initialProvisioningProjectId } from '../app/utils/provisioning.ts'

test('keeps a filtered project only when it is in the writable project scope', () => {
  assert.equal(initialProvisioningProjectId('project-a', ['project-a', 'project-b']), 'project-a')
  assert.equal(initialProvisioningProjectId('project-c', ['project-a', 'project-b']), null)
  assert.equal(initialProvisioningProjectId(null, ['project-a', 'project-b']), null)
})
