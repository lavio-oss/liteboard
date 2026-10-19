import assert from 'node:assert/strict'
import test from 'node:test'
import type { Device, DeviceProfile } from '../app/types/admin.ts'
import type { AlertRule, WebhookEndpoint } from '../app/types/alerting.ts'
import { DRAFT_GROUP_PREFIX, WORKFLOW_GRID, buildAlertWorkflowGraph, parseWorkflowNodeId } from '../app/utils/alertWorkflowGraph.ts'

const at = '2026-10-18T08:00:00.000000Z'
const id = (n: number) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`

const version = (n: number, profileId: string, number: number): DeviceProfile['versions'][number] => ({
  id: id(n), project_id: id(0), device_profile_id: profileId, version: number, schema_code: `s${n}`,
  definition: { strict_mode: true, fields: { temperature: { type: 'float', unit: 'C', required: true } } },
  compatibility_note: null, created_at: at, updated_at: at
})
const profile = (n: number, name: string, versions: number[]): DeviceProfile => ({
  id: id(n), project_id: id(0), code: name.toLowerCase(), name, description: null, created_at: at, updated_at: at,
  versions: versions.map((v, i) => version(n * 10 + i, id(n), v))
})
const device = (n: number, name: string, versionId: string | null) => ({
  id: id(n), name, code: `d${n}`, device_profile_version_id: versionId
}) as Device
const rule = (n: number, profileId: string, status: 'active' | 'archived' = 'active') => ({
  id: id(n), project_id: id(0), device_profile_id: profileId, code: `r${n}`, name: `Rule ${n}`, status,
  current_revision: null, archived_at: null, created_at: at, updated_at: at
}) as AlertRule
const webhook = (n: number, alertRuleIds: string[]) => ({
  id: id(n), name: `Hook ${n}`, alert_rule_ids: alertRuleIds
}) as WebhookEndpoint

const boiler = profile(1, 'Boiler', [2, 1])
const pump = profile(2, 'Pump', [1])
const source = {
  profiles: [pump, boiler],
  devices: [device(31, 'Unassigned', null), device(32, 'Pump A', pump.versions[0]!.id), device(33, 'Boiler A', boiler.versions[1]!.id)],
  rules: [rule(41, pump.id), rule(42, boiler.id), rule(43, boiler.id, 'archived')],
  webhooks: [webhook(51, []), webhook(52, [id(41), id(43)])]
}

test('splits profiles into version nodes; only versions with devices get a group, on the same row', () => {
  const { nodes } = buildAlertWorkflowGraph(source)
  const column = (kind: string) => nodes.filter(node => node.data.kind === kind)
  const [boilerV1, boilerV2, pumpV1] = [boiler.versions[1]!, boiler.versions[0]!, pump.versions[0]!]

  assert.deepEqual(column('version').map(node => [node.id, node.data.label]), [
    [`version:${boilerV1.id}`, 'Boiler v1'],
    [`version:${boilerV2.id}`, 'Boiler v2'],
    [`version:${pumpV1.id}`, 'Pump v1']
  ])
  // Boiler v2 has no device, and the unassigned device is not drawn at all.
  assert.deepEqual(column('group').map(node => [node.id, node.data.sublabel, node.position.y]), [
    [`group:${boilerV1.id}`, '1 device(s)', 0],
    [`group:${pumpV1.id}`, '1 device(s)', 2 * WORKFLOW_GRID.rowHeight]
  ])
  assert.deepEqual(column('rule').map(node => node.data.label), ['Rule 42', 'Rule 41'])
  assert.deepEqual(column('input')[0]!.position, { x: 0, y: WORKFLOW_GRID.rowHeight })
})

test('derives edges: every version of a profile feeds its rules; only webhook filters are removable', () => {
  const { edges } = buildAlertWorkflowGraph(source)
  const find = (sourceId: string, target: string) => edges.find(edge => edge.source === sourceId && edge.target === target)
  const [boilerV1, boilerV2, pumpV1] = [boiler.versions[1]!, boiler.versions[0]!, pump.versions[0]!]

  assert.equal(find('input', `group:${boilerV1.id}`)?.data.removable, false)
  assert.equal(find(`group:${boilerV1.id}`, `version:${boilerV1.id}`)?.data.removable, false)
  assert.equal(edges.some(edge => edge.source === `group:${boilerV2.id}` || edge.target === `group:${boilerV2.id}`), false)
  assert.ok(find(`version:${boilerV1.id}`, `rule:${id(42)}`))
  assert.ok(find(`version:${boilerV2.id}`, `rule:${id(42)}`))
  assert.ok(find(`version:${pumpV1.id}`, `rule:${id(41)}`))
  assert.equal(find(`version:${pumpV1.id}`, `rule:${id(42)}`), undefined)
  // A webhook only gets edges from the rules it lists; a new rule is not connected anywhere.
  assert.equal(edges.some(edge => edge.target === `webhook:${id(51)}`), false)
  // Listed rules become removable edges; the archived rule has no node, so no edge.
  assert.deepEqual(edges.filter(edge => edge.target === `webhook:${id(52)}`).map(edge => [edge.source, edge.data.removable]), [
    [`rule:${id(41)}`, true]
  ])
})

test('adds draft groups below the last version row, linked to the input only', () => {
  const { nodes, edges } = buildAlertWorkflowGraph({ ...source, drafts: [{ id: `${DRAFT_GROUP_PREFIX}1`, deviceIds: [id(31), id(32)] }] })
  const draft = nodes.find(node => node.id === `group:${DRAFT_GROUP_PREFIX}1`)!

  assert.deepEqual([draft.data.label, draft.data.sublabel], ['New group 1', '2 device(s) · not assigned'])
  assert.equal(draft.position.y, 3 * WORKFLOW_GRID.rowHeight)
  assert.deepEqual(edges.filter(edge => edge.source === draft.id || edge.target === draft.id).map(edge => edge.source), ['input'])
})

test('parses node ids and rejects unknown ones', () => {
  assert.deepEqual(parseWorkflowNodeId(`rule:${id(41)}`), { kind: 'rule', entityId: id(41) })
  assert.throws(() => parseWorkflowNodeId('input'))
  assert.throws(() => parseWorkflowNodeId(`asset:${id(1)}`))
})
