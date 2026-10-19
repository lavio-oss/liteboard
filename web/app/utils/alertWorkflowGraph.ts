import type { Device, DeviceProfile } from '~/types/admin'
import type { AlertRule, WebhookEndpoint } from '~/types/alerting'

export type WorkflowKind = 'input' | 'group' | 'version' | 'rule' | 'webhook'

export const WORKFLOW_COLUMNS: WorkflowKind[] = ['input', 'group', 'version', 'rule', 'webhook']
export const WORKFLOW_GRID = { columnWidth: 400, rowHeight: 160 }
// Draft groups only live in the page session until they are connected to a profile version.
export const DRAFT_GROUP_PREFIX = 'draft-'

export interface DraftGroup {
  id: string
  deviceIds: string[]
}

export interface WorkflowNodeData {
  kind: WorkflowKind
  entityId: string | null
  label: string
  sublabel: string | null
}

export interface WorkflowNode {
  id: string
  type: 'workflow'
  position: { x: number, y: number }
  data: WorkflowNodeData
}

export interface WorkflowEdge {
  id: string
  source: string
  target: string
  // removable edges map to a write (a webhook's rule list); the rest are derived.
  data: { removable: boolean }
}

interface WorkflowSource {
  devices: Device[]
  profiles: DeviceProfile[]
  rules: AlertRule[]
  webhooks: WebhookEndpoint[]
  drafts?: DraftGroup[]
}

const nodeId = (kind: WorkflowKind, entityId: string) => `${kind}:${entityId}`

/** Maps every profile version id to its profile. */
export function versionProfiles(profiles: DeviceProfile[]): Map<string, DeviceProfile> {
  return new Map(profiles.flatMap(profile => profile.versions.map(version => [version.id, profile] as const)))
}

/**
 * Derives the Input → Device group → Profile version → Rule → Webhook graph from data and places every
 * node on a fixed column/row grid, so any refresh re-formats the canvas. A device group is the set of
 * devices assigned to one profile version; it only exists while the version has devices and sits on
 * that version's row. Devices without a version are not drawn. A rule belongs to a profile, so every
 * version of the profile feeds it.
 */
export function buildAlertWorkflowGraph({ devices, profiles, rules, webhooks, drafts = [] }: WorkflowSource) {
  const sortedProfiles = [...profiles].sort((a, b) => a.name.localeCompare(b.name))
  const profileOrder = new Map(sortedProfiles.map((profile, index) => [profile.id, index]))
  const versions = sortedProfiles.flatMap(profile => [...profile.versions]
    .sort((a, b) => a.version - b.version)
    .map(version => ({ version, profile })))
  const sortedDevices = [...devices].sort((a, b) => a.name.localeCompare(b.name))
  const membersOf = (versionId: string) => sortedDevices.filter(device => device.device_profile_version_id === versionId)
  const activeRules = rules
    .filter(rule => rule.status === 'active')
    .sort((a, b) => (profileOrder.get(a.device_profile_id) ?? sortedProfiles.length) - (profileOrder.get(b.device_profile_id) ?? sortedProfiles.length)
      || a.name.localeCompare(b.name))
  const sortedWebhooks = [...webhooks].sort((a, b) => a.name.localeCompare(b.name))

  // row: grid row; groups follow their version's row and drafts go below the last version.
  const columns: Record<WorkflowKind, (WorkflowNodeData & { row?: number })[]> = {
    input: [{ kind: 'input', entityId: null, label: 'Telemetry', sublabel: 'MQTT ingest' }],
    group: [
      ...versions.flatMap(({ version, profile }, row) => membersOf(version.id).length === 0
        ? []
        : [{
            kind: 'group' as const,
            entityId: version.id,
            label: `${profile.name} v${version.version} devices`,
            sublabel: `${membersOf(version.id).length} device(s)`,
            row
          }]),
      ...drafts.map((draft, index) => ({
        kind: 'group' as const,
        entityId: draft.id,
        label: `New group ${index + 1}`,
        sublabel: `${draft.deviceIds.length} device(s) · not assigned`,
        row: versions.length + index
      }))
    ],
    version: versions.map(({ version, profile }) => ({
      kind: 'version' as const,
      entityId: version.id,
      label: `${profile.name} v${version.version}`,
      sublabel: version.schema_code
    })),
    rule: activeRules.map(rule => ({
      kind: 'rule' as const,
      entityId: rule.id,
      label: rule.name,
      sublabel: rule.current_revision === null
        ? rule.code
        : `${rule.current_revision.definition.field} · ${rule.current_revision.definition.severity}`
    })),
    webhook: sortedWebhooks.map(webhook => ({
      kind: 'webhook' as const,
      entityId: webhook.id,
      label: webhook.name,
      sublabel: `${webhook.alert_rule_ids.length} rule(s)`
    }))
  }

  const groupRows = columns.group.map(group => group.row ?? 0)
  // The input sits halfway between the first and last device group.
  const inputRow = groupRows.length === 0 ? 0 : (Math.min(...groupRows) + Math.max(...groupRows)) / 2

  const nodes: WorkflowNode[] = WORKFLOW_COLUMNS.flatMap((kind, column) => columns[kind].map((item, index) => ({
    id: item.entityId === null ? kind : nodeId(kind, item.entityId),
    type: 'workflow' as const,
    position: {
      x: column * WORKFLOW_GRID.columnWidth,
      y: (kind === 'input' ? inputRow : item.row ?? index) * WORKFLOW_GRID.rowHeight
    },
    data: { kind: item.kind, entityId: item.entityId, label: item.label, sublabel: item.sublabel }
  })))

  const nodeIds = new Set(nodes.map(node => node.id))
  const edge = (source: string, target: string, removable = false): WorkflowEdge => ({
    id: `${source}->${target}`,
    source,
    target,
    data: { removable }
  })

  const edges = [
    ...columns.group.map(group => edge('input', nodeId('group', group.entityId!))),
    ...versions.map(({ version }) => edge(nodeId('group', version.id), nodeId('version', version.id))),
    ...activeRules.flatMap(rule => (sortedProfiles.find(profile => profile.id === rule.device_profile_id)?.versions ?? [])
      .map(version => edge(nodeId('version', version.id), nodeId('rule', rule.id)))),
    ...sortedWebhooks.flatMap(webhook => webhook.alert_rule_ids.map(ruleId => edge(nodeId('rule', ruleId), nodeId('webhook', webhook.id), true)))
  ].filter(item => nodeIds.has(item.source) && nodeIds.has(item.target))

  return { nodes, edges }
}

/** Splits a workflow node id such as `group:<uuid>` back into its kind and entity id. */
export function parseWorkflowNodeId(id: string): { kind: WorkflowKind, entityId: string } {
  const [kind, entityId] = id.split(':') as [WorkflowKind, string | undefined]

  if (!WORKFLOW_COLUMNS.includes(kind) || entityId === undefined) {
    throw new Error(`Unknown workflow node id: ${id}`)
  }

  return { kind, entityId }
}
