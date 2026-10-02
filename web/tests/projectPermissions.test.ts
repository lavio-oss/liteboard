import assert from 'node:assert/strict'
import test from 'node:test'
import {
  canDeleteProjectData,
  canManageProjectMembers,
  canWriteProjectData
} from '../app/utils/projectPermissions.ts'

test('project admins can manage data and members in their assigned project', () => {
  assert.equal(canWriteProjectData('admin', false), true)
  assert.equal(canDeleteProjectData('admin', false), true)
  assert.equal(canManageProjectMembers('admin', false), true)
})

test('project engineers can create and edit data but cannot delete or manage members', () => {
  assert.equal(canWriteProjectData('engineer', false), true)
  assert.equal(canDeleteProjectData('engineer', false), false)
  assert.equal(canManageProjectMembers('engineer', false), false)
})

test('read-only project roles cannot perform write actions', () => {
  for (const role of ['operator', 'viewer', null] as const) {
    assert.equal(canWriteProjectData(role, false), false)
    assert.equal(canDeleteProjectData(role, false), false)
    assert.equal(canManageProjectMembers(role, false), false)
  }
})

test('tenant and platform managers retain management rights for their tenant scope', () => {
  assert.equal(canWriteProjectData(null, true), true)
  assert.equal(canDeleteProjectData(null, true), true)
  assert.equal(canManageProjectMembers(null, true), true)
})
