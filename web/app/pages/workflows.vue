<script setup lang="ts">
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { Handle, Position, VueFlow } from '@vue-flow/core'
import type { Connection, EdgeMouseEvent, VueFlowStore } from '@vue-flow/core'
import type { DeviceGroupChange } from '~/components/alerting/DeviceGroupModal.vue'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useAlerting } from '~/composables/useAlerting'
import { useAlertingProject } from '~/composables/useAlertingProject'
import type { Device, DeviceInput, DeviceProfile, DeviceProfileInput } from '~/types/admin'
import type { AlertRule, AlertRuleInput, WebhookEndpoint, WebhookEndpointInput } from '~/types/alerting'
import { describeAlertRule } from '~/utils/alertRuleDefinition'
import { DRAFT_GROUP_PREFIX, WORKFLOW_COLUMNS, buildAlertWorkflowGraph, parseWorkflowNodeId, versionProfiles } from '~/utils/alertWorkflowGraph'
import type { DraftGroup, WorkflowEdge, WorkflowKind } from '~/utils/alertWorkflowGraph'

definePageMeta({
  middleware: 'auth'
})

const toast = useToast()
const { createDevice, createDeviceProfile, loadDeviceProfiles, loadDevices, updateDevice } = useAdmin()
const alerting = useAlerting()
const {
  canAdminInSelectedProject,
  canWriteInSelectedProject,
  formProjectOptions,
  notifyError,
  projectSelectOptions,
  projects,
  projectsError,
  projectsStatus,
  requireProject,
  selectedProject,
  selectedProjectId
} = await useAlertingProject()

const { data, status, error, refresh } = await useAsyncData(
  'liteboard-alert-workflow',
  async () => {
    const project = selectedProject.value

    if (project === null) {
      return { devices: [] as Device[], profiles: [] as DeviceProfile[], rules: [] as AlertRule[], webhooks: [] as WebhookEndpoint[] }
    }

    // shortcut: loadDevices reads the first 100 devices only; page through them once projects outgrow that.
    const [devices, profiles, rules, webhooks] = await Promise.all([
      loadDevices(project.tenant_id, project.id),
      loadDeviceProfiles(project.tenant_id, project.id),
      alerting.loadAlertRules(project.tenant_id, project.id),
      // Operators and viewers may not read webhook endpoints.
      canWriteInSelectedProject.value ? alerting.loadWebhookEndpoints(project.tenant_id, project.id) : Promise.resolve([])
    ])

    return { devices, profiles, rules, webhooks }
  },
  { watch: [selectedProjectId] }
)

const source = computed(() => data.value ?? { devices: [], profiles: [], rules: [], webhooks: [] })
// Groups created without a profile; they exist until connected to a profile or removed, and are lost on reload.
const drafts = ref<DraftGroup[]>([])
const graph = computed(() => buildAlertWorkflowGraph({ ...source.value, drafts: drafts.value }))
const isDraft = (entityId: string) => entityId.startsWith(DRAFT_GROUP_PREFIX)
let draftCounter = 0
watch(selectedProjectId, () => {
  drafts.value = []
})
const profileByVersion = computed(() => versionProfiles(source.value.profiles))

// Same visual language as TenantHierarchyFlow: icon colour plus a matching subtle badge.
const KIND_STYLE = {
  input: { label: 'Input', icon: 'i-lucide-radio-tower', iconClass: 'text-sky-500', badgeClass: 'bg-sky-500/10 text-sky-600 dark:text-sky-400 ring-sky-500/25', stroke: '#0ea5e9' },
  group: { label: 'Device group', icon: 'i-lucide-router', iconClass: 'text-teal-500', badgeClass: 'bg-teal-500/10 text-teal-600 dark:text-teal-400 ring-teal-500/25', stroke: '#14b8a6' },
  version: { label: 'Profile version', icon: 'i-lucide-git-commit-horizontal', iconClass: 'text-purple-500', badgeClass: 'bg-purple-500/10 text-purple-600 dark:text-purple-400 ring-purple-500/25', stroke: '#a855f7' },
  rule: { label: 'Rule', icon: 'i-lucide-list-checks', iconClass: 'text-amber-500', badgeClass: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 ring-amber-500/25', stroke: '#f59e0b' },
  webhook: { label: 'Webhook', icon: 'i-lucide-webhook', iconClass: 'text-rose-500', badgeClass: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 ring-rose-500/25', stroke: '#f43f5e' }
} satisfies Record<WorkflowKind, { label: string, icon: string, iconClass: string, badgeClass: string, stroke: string }>

// Animated dashed bezier edges (smoothstep draws square corners), coloured by source kind; removable ones
// turn red on hover (see the style block).
const flowEdges = computed(() => graph.value.edges.map((edge) => {
  const stroke = KIND_STYLE[edge.source === 'input' ? 'input' : parseWorkflowNodeId(edge.source).kind].stroke

  return {
    ...edge,
    type: 'default',
    animated: true,
    interactionWidth: 24,
    class: edge.data.removable ? 'workflow-edge-removable' : undefined,
    style: { stroke, strokeWidth: 2.5 }
  }
}))

const findWebhook = (id: string) => source.value.webhooks.find(webhook => webhook.id === id)
const groupMembers = (versionId: string) => source.value.devices.filter(device => device.device_profile_version_id === versionId)

// Drawn by hand: device group → profile version (assigns the group's devices to it) and rule → webhook.
// Set on both handles because Vue Flow validates with the handle the drag starts from; the VueFlow-level
// prop would also validate, and drop, every existing edge when they are loaded.
function groupDeviceIds(entityId: string): string[] {
  return isDraft(entityId)
    ? drafts.value.find(draft => draft.id === entityId)?.deviceIds ?? []
    : groupMembers(entityId).map(device => device.id)
}

function isValidConnection(connection: Connection): boolean {
  if (connection.source === 'input' || connection.target === 'input') {
    return false
  }

  const from = parseWorkflowNodeId(connection.source)
  const to = parseWorkflowNodeId(connection.target)

  if (from.kind === 'group' && to.kind === 'version') {
    return canWriteInSelectedProject.value && from.entityId !== to.entityId && groupDeviceIds(from.entityId).length > 0
  }

  if (from.kind === 'rule' && to.kind === 'webhook') {
    const webhook = findWebhook(to.entityId)

    return canAdminInSelectedProject.value && webhook !== undefined && !webhook.alert_rule_ids.includes(from.entityId)
  }

  return false
}

// Every canvas write is confirmed first, then reloads data so the graph re-formats on the grid.
const pending = ref<{ title: string, description: string, run: () => Promise<unknown> } | null>(null)
const pendingOpen = ref(false)
const pendingLoading = ref(false)

function confirm(title: string, description: string, run: () => Promise<unknown>): void {
  pending.value = { title, description, run }
  pendingOpen.value = true
}

async function runPending(): Promise<void> {
  if (pending.value === null) {
    throw new Error('No pending workflow change.')
  }

  pendingLoading.value = true

  try {
    await pending.value.run()
    await refresh()
    pendingOpen.value = false
  } catch (cause: unknown) {
    notifyError(cause, pending.value.title)
  } finally {
    pendingLoading.value = false
  }
}

function setDeviceVersion(deviceId: string, versionId: string | null) {
  const project = requireProject()

  return updateDevice(project.tenant_id, project.id, deviceId, { device_profile_version_id: versionId })
}

// shortcut: one PATCH per device, not atomic; a failure leaves the earlier ones applied and the reload shows it.
async function applyDeviceChanges(changes: DeviceGroupChange[]): Promise<void> {
  for (const change of changes) {
    await setDeviceVersion(change.deviceId, change.versionId)
  }
}

function setWebhookRules(webhookId: string, ruleIds: string[]) {
  const project = requireProject()

  return alerting.updateWebhookEndpoint(project.tenant_id, project.id, webhookId, { alert_rule_ids: ruleIds })
}

// Telemetry is rejected unless its schema_version equals the version number of the device's profile version.
function schemaWarning(versionId: string): string {
  const version = profileByVersion.value.get(versionId)?.versions.find(item => item.id === versionId)

  return version === undefined
    ? ''
    : ` Their firmware must send schema_version ${version.version}, otherwise their telemetry is rejected.`
}

function nodeLabel(id: string): string {
  return graph.value.nodes.find(node => node.id === id)?.data.label ?? id
}

function onConnect(connection: Connection): void {
  if (!isValidConnection(connection)) {
    return
  }

  const from = parseWorkflowNodeId(connection.source)
  const to = parseWorkflowNodeId(connection.target)

  if (from.kind === 'group') {
    const deviceIds = groupDeviceIds(from.entityId)

    confirm(
      'Assign profile version',
      `${deviceIds.length} device(s) of ${nodeLabel(connection.source)} will validate telemetry against ${nodeLabel(connection.target)} and be evaluated by its profile's rules.${schemaWarning(to.entityId)}`,
      async () => {
        await applyDeviceChanges(deviceIds.map(deviceId => ({ deviceId, versionId: to.entityId })))
        // Assigned devices are real data now, so a connected draft goes away.
        drafts.value = drafts.value.filter(draft => draft.id !== from.entityId)
      }
    )

    return
  }

  const webhook = findWebhook(to.entityId)

  if (webhook === undefined) {
    throw new Error('The webhook is not available.')
  }

  confirm(
    'Route rule to webhook',
    `Alerts of ${nodeLabel(connection.source)} will be delivered to ${nodeLabel(connection.target)}.`,
    () => setWebhookRules(webhook.id, [...webhook.alert_rule_ids, from.entityId])
  )
}

function onEdgeClick({ edge }: EdgeMouseEvent): void {
  const { removable } = edge.data as WorkflowEdge['data']

  if (!removable || !canAdminInSelectedProject.value) {
    return
  }

  const from = parseWorkflowNodeId(edge.source)
  const to = parseWorkflowNodeId(edge.target)
  const webhook = findWebhook(to.entityId)

  if (webhook === undefined) {
    throw new Error('The webhook is not available.')
  }

  confirm(
    'Stop routing rule',
    `Alerts of ${nodeLabel(edge.source)} will no longer be delivered to ${nodeLabel(edge.target)}.`,
    () => setWebhookRules(webhook.id, webhook.alert_rule_ids.filter(id => id !== from.entityId))
  )
}

// Palette: dropping a component opens a picker. A device group ticks devices; profile version, rule and
// webhook select one existing entity and focus its node. "Add new" in a picker opens the create form
// (a profile, since versions are published on the profile page).
type PaletteKind = 'group' | 'version' | 'rule' | 'webhook'
type PickKind = Exclude<PaletteKind, 'group'>
type FormKind = 'group' | 'pick' | 'device' | 'profile' | 'rule' | 'webhook'
const PALETTE_MIME = 'application/x-liteboard-workflow'
const palette = computed(() => [
  { kind: 'group' as const, enabled: canWriteInSelectedProject.value },
  { kind: 'version' as const, enabled: true },
  { kind: 'rule' as const, enabled: true },
  // Webhooks are only loaded for Admin/Engineer.
  { kind: 'webhook' as const, enabled: canWriteInSelectedProject.value }
])

const activeForm = ref<FormKind | null>(null)
const formLoading = ref(false)
const formProjectId = ref<string | null>(null)
const formEquipmentAssetId = ref<string | null>(null)
const formDeviceProfileVersionId = ref<string | null>(null)
const pickKind = ref<PickKind>('version')
// The group the device modal edits: a profile version's group, a draft, or null for a new group.
const groupTarget = ref<string | null>(null)
const groupVersionId = computed(() => groupTarget.value === null || isDraft(groupTarget.value) ? null : groupTarget.value)
const groupInitialIds = computed(() => {
  const target = groupTarget.value

  if (target === null) {
    return []
  }

  return isDraft(target)
    ? drafts.value.find(draft => draft.id === target)?.deviceIds ?? []
    : groupMembers(target).map(device => device.id)
})
const groupTitle = computed(() => groupTarget.value === null
  ? 'New device group'
  : `Devices of ${nodeLabel(`group:${groupTarget.value}`)}`)
const editingRule = ref<AlertRule | null>(null)
const credentialsOpen = ref(false)
const credentialsDevice = ref<Device | null>(null)
const secretOpen = ref(false)
const revealedSecret = ref('')

function openCreate(kind: string): void {
  const item = palette.value.find(entry => entry.kind === kind && entry.enabled)

  // Ignores foreign drops (files, text) and kinds the user may not use.
  if (item === undefined) {
    return
  }

  if (item.kind === 'group') {
    groupTarget.value = null
    activeForm.value = 'group'
  } else {
    pickKind.value = item.kind
    activeForm.value = 'pick'
  }
}

const pickTitle = computed(() => `Select ${KIND_STYLE[pickKind.value].label.toLowerCase()}`)
const pickCanCreate = computed(() => pickKind.value === 'webhook' ? canAdminInSelectedProject.value : canWriteInSelectedProject.value)
// Profile versions are picked profile first, then version.
const pickParentItems = computed(() => pickKind.value === 'version'
  ? [...source.value.profiles]
      .filter(profile => profile.versions.length > 0)
      .sort((a, b) => a.name.localeCompare(b.name))
      .map(profile => ({ label: profile.name, value: profile.id, description: profile.code }))
  : undefined)
const pickItems = computed(() => {
  if (pickKind.value === 'version') {
    return [...source.value.profiles]
      .sort((a, b) => a.name.localeCompare(b.name))
      .flatMap(profile => [...profile.versions]
        .sort((a, b) => b.version - a.version)
        .map(version => ({ label: `v${version.version}`, value: version.id, description: version.schema_code, parent: profile.id })))
  }

  if (pickKind.value === 'rule') {
    return source.value.rules
      .filter(rule => rule.status === 'active')
      .map(rule => ({ label: rule.name, value: rule.id, description: rule.code }))
  }

  return source.value.webhooks.map(webhook => ({ label: webhook.name, value: webhook.id, description: webhook.url }))
})

function onPick(entityId: string): void {
  activeForm.value = null
  focusNode(`${pickKind.value}:${entityId}`)
}

function onPickCreate(): void {
  formProjectId.value = requireProject().id
  editingRule.value = null
  activeForm.value = pickKind.value === 'version' ? 'profile' : pickKind.value
}

function onGroupCreate(): void {
  formProjectId.value = requireProject().id
  formEquipmentAssetId.value = null
  formDeviceProfileVersionId.value = null
  activeForm.value = 'device'
}

// Focus: zoom to a node and ring it for a moment.
const flow = shallowRef<VueFlowStore | null>(null)
const highlightedNodeId = ref<string | null>(null)

function focusNode(nodeId: string): void {
  highlightedNodeId.value = nodeId
  nextTick(() => flow.value?.fitView({ nodes: [nodeId], duration: 500, maxZoom: 1.2 }))
  setTimeout(() => {
    if (highlightedNodeId.value === nodeId) {
      highlightedNodeId.value = null
    }
  }, 3000)
}

function onPaletteDragStart(event: DragEvent, kind: PaletteKind): void {
  event.dataTransfer?.setData(PALETTE_MIME, kind)
}

function onCanvasDrop(event: DragEvent): void {
  openCreate(event.dataTransfer?.getData(PALETTE_MIME) ?? '')
}

async function submitForm<T>(errorTitle: string, run: () => Promise<T>): Promise<T | undefined> {
  formLoading.value = true

  try {
    const result = await run()
    await refresh()
    activeForm.value = null

    return result
  } catch (cause: unknown) {
    notifyError(cause, errorTitle)

    return undefined
  } finally {
    formLoading.value = false
  }
}

async function saveDevice(payload: DeviceInput): Promise<void> {
  const project = requireProject()
  const device = await submitForm('Unable to create device', () => createDevice(project.tenant_id, project.id, payload))

  if (device !== undefined) {
    credentialsDevice.value = device
    credentialsOpen.value = true
  }
}

function openGroup(entityId: string): void {
  groupTarget.value = entityId
  activeForm.value = 'group'
}

async function saveGroup(changes: DeviceGroupChange[]): Promise<void> {
  await submitForm('Unable to update device group', () => applyDeviceChanges(changes))
}

// A draft is edited in place; otherwise a new one is added.
function saveDraft(deviceIds: string[]): void {
  const target = groupTarget.value

  drafts.value = target !== null && isDraft(target)
    ? drafts.value.map(draft => draft.id === target ? { ...draft, deviceIds } : draft)
    : [...drafts.value, { id: `${DRAFT_GROUP_PREFIX}${++draftCounter}`, deviceIds }]
  activeForm.value = null
}

async function saveProfile(payload: DeviceProfileInput): Promise<void> {
  const project = requireProject()
  await submitForm('Unable to create profile', () => createDeviceProfile(project.tenant_id, project.id, payload))
}

async function saveRule({ input }: { projectId: string, input: AlertRuleInput }): Promise<void> {
  const project = requireProject()
  const rule = editingRule.value
  const saved = await submitForm('Unable to save rule', () => rule === null
    ? alerting.createAlertRule(project.tenant_id, project.id, input)
    : alerting.createAlertRuleRevision(project.tenant_id, project.id, rule.id, input.definition))

  if (saved !== undefined) {
    toast.add({ title: rule === null ? 'Rule created' : 'Revision created', description: 'The rule applies to telemetry received from now on.', color: 'success' })
  }
}

async function saveWebhook({ input }: { projectId: string, input: WebhookEndpointInput }): Promise<void> {
  const project = requireProject()
  const created = await submitForm('Unable to create webhook', () => alerting.createWebhookEndpoint(project.tenant_id, project.id, input))

  if (created !== undefined) {
    revealedSecret.value = created.secret
    secretOpen.value = true
  }
}

watch(secretOpen, (isOpen) => {
  if (!isOpen) {
    revealedSecret.value = ''
  }
})

const formOpen = (kind: FormKind) => computed({
  get: () => activeForm.value === kind,
  set: (open: boolean) => {
    activeForm.value = open ? kind : null
  }
})
const groupFormOpen = formOpen('group')
const pickFormOpen = formOpen('pick')
const deviceFormOpen = formOpen('device')
const profileFormOpen = formOpen('profile')
const ruleFormOpen = formOpen('rule')
const webhookFormOpen = formOpen('webhook')

const paletteOpen = ref(false)

/**
 * "Remove from canvas" removes the node's writable relationships, never the entity itself:
 * a device group's devices lose their profile, a rule leaves every webhook filter, a webhook
 * stops receiving any rule. Profile→rule links are fixed by the data model.
 */
function unlinkPlan(nodeId: string): { description: string, run: () => Promise<unknown> } | null {
  if (nodeId === 'input') {
    return null
  }

  const { kind, entityId } = parseWorkflowNodeId(nodeId)
  const label = nodeLabel(nodeId)

  if (kind === 'group' && isDraft(entityId)) {
    return {
      description: `${label} will be removed from the canvas. Its devices keep their current profile.`,
      run: () => Promise.resolve(drafts.value = drafts.value.filter(draft => draft.id !== entityId))
    }
  }

  if (kind === 'group' && canWriteInSelectedProject.value) {
    const members = groupMembers(entityId)

    return members.length === 0
      ? null
      : {
          description: `${members.length} device(s) of ${label} will be unassigned and no longer evaluated by rules.`,
          run: () => applyDeviceChanges(members.map(device => ({ deviceId: device.id, versionId: null })))
        }
  }

  if (kind === 'rule' && canAdminInSelectedProject.value) {
    const filtering = source.value.webhooks.filter(webhook => webhook.alert_rule_ids.includes(entityId))

    return filtering.length === 0
      ? null
      : { description: `${label} will no longer be delivered to ${filtering.length} webhook(s).`, run: async () => {
          for (const webhook of filtering) {
            await setWebhookRules(webhook.id, webhook.alert_rule_ids.filter(id => id !== entityId))
          }
        } }
  }

  if (kind === 'webhook' && canAdminInSelectedProject.value) {
    return findWebhook(entityId)?.alert_rule_ids.length === 0
      ? null
      : { description: `${label} will stop receiving alerts from every rule.`, run: () => setWebhookRules(entityId, []) }
  }

  return null
}

function removeFromCanvas(nodeId: string): void {
  const plan = unlinkPlan(nodeId)

  if (plan === null) {
    throw new Error(`Node ${nodeId} has no removable relationship.`)
  }

  confirm('Remove from canvas', plan.description, plan.run)
}

// Details modal, modelled on the TenantHierarchyFlow one.
const detailsNodeId = ref<string | null>(null)
const detailsOpen = ref(false)
const detailsNode = computed(() => graph.value.nodes.find(node => node.id === detailsNodeId.value) ?? null)

function openDetails(nodeId: string): void {
  detailsNodeId.value = nodeId
  detailsOpen.value = true
}

// An array value renders as one badge per item.
const detailFields = computed<{ label: string, value: string | string[] }[]>(() => {
  const node = detailsNode.value

  if (node === null) {
    return []
  }

  if (node.id === 'input') {
    return [{ label: 'Transport', value: 'MQTT telemetry' }, { label: 'Devices', value: String(source.value.devices.length) }]
  }

  const { kind, entityId } = parseWorkflowNodeId(node.id)
  const fail = (): never => {
    throw new Error(`The ${kind} ${entityId} is not loaded.`)
  }

  if (kind === 'group') {
    const deviceIds = groupDeviceIds(entityId)
    const members = source.value.devices.filter(device => deviceIds.includes(device.id))

    return [
      { label: 'Profile version', value: isDraft(entityId) ? '—' : nodeLabel(`version:${entityId}`) },
      { label: 'Devices', value: members.map(device => device.name) },
      { label: 'Online', value: String(members.filter(device => device.connection_state === 'online').length) }
    ]
  }

  if (kind === 'version') {
    const profile = profileByVersion.value.get(entityId) ?? fail()
    const version = profile.versions.find(item => item.id === entityId) ?? fail()

    return [
      { label: 'Profile', value: profile.name },
      { label: 'Version', value: `v${version.version}` },
      { label: 'Schema code', value: version.schema_code },
      { label: 'Fields', value: Object.keys(version.definition.fields) },
      { label: 'Devices', value: String(groupMembers(entityId).length) },
      { label: 'Active rules', value: String(source.value.rules.filter(rule => rule.device_profile_id === profile.id && rule.status === 'active').length) }
    ]
  }

  if (kind === 'rule') {
    const rule = source.value.rules.find(item => item.id === entityId) ?? fail()

    return [
      { label: 'Code', value: rule.code },
      { label: 'Status', value: rule.status },
      { label: 'Revision', value: rule.current_revision === null ? '—' : `v${rule.current_revision.revision}` },
      { label: 'Condition', value: rule.current_revision === null ? '—' : describeAlertRule(rule.current_revision.definition) },
      { label: 'Severity', value: rule.current_revision?.definition.severity ?? '—' }
    ]
  }

  const webhook = findWebhook(entityId) ?? fail()

  return [
    { label: 'URL', value: webhook.url },
    { label: 'Enabled', value: webhook.enabled ? 'yes' : 'no' },
    { label: 'Events', value: webhook.event_types },
    { label: 'Rules', value: webhook.alert_rule_ids.length === 0 ? '—' : webhook.alert_rule_ids.map(ruleId => nodeLabel(`rule:${ruleId}`)) }
  ]
})

function openDetailsPage(): void {
  const node = detailsNode.value

  if (node === null || node.id === 'input') {
    throw new Error('The selected node has no page.')
  }

  const { kind, entityId } = parseWorkflowNodeId(node.id)
  const project = requireProject()
  detailsOpen.value = false

  if (kind === 'rule') {
    editingRule.value = source.value.rules.find(rule => rule.id === entityId) ?? null
    activeForm.value = editingRule.value === null ? null : 'rule'
  } else if (kind === 'group') {
    navigateTo('/devices', { open: { target: '_blank' } })
  } else if (kind === 'version') {
    navigateTo(`/device-profiles/${project.id}/${profileByVersion.value.get(entityId)?.id}`, { open: { target: '_blank' } })
  } else {
    navigateTo('/webhooks', { open: { target: '_blank' } })
  }
}

const errorMessage = computed(() => {
  if (projectsError.value instanceof ApiError) {
    return projectsError.value.message
  }

  if (error.value instanceof ApiError) {
    return error.value.message
  }

  return 'The workflow could not be loaded.'
})
</script>

<template>
  <UDashboardPanel id="liteboard-workflows" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Workflow">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <USelect
            v-if="projects.length > 1"
            v-model="selectedProjectId"
            :items="projectSelectOptions"
            class="min-w-44"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="projectsStatus === 'error' || status === 'error'"
          color="error"
          variant="subtle"
          title="Unable to load the workflow"
          :description="errorMessage"
        />

        <div class="flex flex-wrap items-center gap-3">
          <UButton
            :label="paletteOpen ? 'Hide components' : 'Components'"
            :icon="paletteOpen ? 'i-lucide-panel-left-close' : 'i-lucide-panel-left-open'"
            color="neutral"
            variant="outline"
            :disabled="!palette.some(item => item.enabled)"
            @click="paletteOpen = !paletteOpen"
          />

          <div class="ms-auto flex flex-wrap items-center gap-2 text-xs">
            <UBadge
              v-for="kind in WORKFLOW_COLUMNS"
              :key="kind"
              variant="subtle"
              class="gap-1.5"
              :class="KIND_STYLE[kind].badgeClass"
            >
              <UIcon :name="KIND_STYLE[kind].icon" class="size-3 shrink-0" :class="KIND_STYLE[kind].iconClass" />
              {{ KIND_STYLE[kind].label }}
            </UBadge>
          </div>
        </div>

        <ClientOnly>
          <div
            class="relative h-[680px] overflow-hidden rounded-xl border border-default bg-elevated/20"
            @dragover.prevent
            @drop.prevent="onCanvasDrop"
          >
            <div
              v-if="paletteOpen"
              class="absolute top-3 left-3 z-10 w-56 rounded-lg border border-default bg-default/95 p-3 shadow-lg backdrop-blur"
            >
              <div class="mb-2 flex items-center justify-between">
                <p class="text-[10px] font-bold uppercase tracking-wider text-muted">
                  Drag to create
                </p>
                <UButton
                  icon="i-lucide-x"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  aria-label="Close components"
                  @click="paletteOpen = false"
                />
              </div>
              <div class="flex flex-col gap-1.5">
                <div
                  v-for="item in palette"
                  :key="item.kind"
                  role="button"
                  tabindex="0"
                  :draggable="item.enabled"
                  :aria-disabled="!item.enabled"
                  :aria-label="`Create ${KIND_STYLE[item.kind].label.toLowerCase()}`"
                  class="flex items-center gap-2 rounded-md border border-default bg-elevated/95 px-2.5 py-2 text-sm shadow-xs transition-all"
                  :class="item.enabled ? 'cursor-grab hover:border-primary/60 hover:shadow-md' : 'cursor-not-allowed opacity-50'"
                  @dragstart="onPaletteDragStart($event, item.kind)"
                  @click="openCreate(item.kind)"
                  @keydown.enter.prevent="openCreate(item.kind)"
                >
                  <UIcon name="i-lucide-grip-vertical" class="size-3.5 shrink-0 text-dimmed" />
                  <UIcon :name="KIND_STYLE[item.kind].icon" class="size-4 shrink-0" :class="KIND_STYLE[item.kind].iconClass" />
                  <span class="font-medium text-highlighted">{{ KIND_STYLE[item.kind].label }}</span>
                </div>
              </div>
            </div>

            <!-- Keyed by project only: data writes update nodes in place, so the viewport is never reset. -->
            <VueFlow
              :key="selectedProjectId"
              :nodes="graph.nodes"
              :edges="flowEdges"
              :nodes-draggable="false"
              :fit-view-on-init="true"
              :elements-selectable="false"
              :delete-key-code="null"
              :min-zoom="0.2"
              :max-zoom="1.5"
              @connect="onConnect"
              @edge-click="onEdgeClick"
              @node-click="({ node }) => openDetails(node.id)"
              @pane-ready="instance => flow = instance"
            >
              <Background pattern-color="#64748b" :gap="24" />
              <Controls position="bottom-left" :show-interactive="false" />

              <template #node-workflow="{ id, data: node }">
                <div
                  class="relative flex w-60 cursor-pointer flex-col justify-between rounded-md border bg-elevated/95 p-3 shadow-xs transition-all hover:border-primary/60 hover:shadow-md"
                  :class="highlightedNodeId === id ? 'border-primary shadow-md ring-2 ring-primary/40' : 'border-default'"
                >
                  <Handle
                    v-if="node.kind !== 'input'"
                    type="target"
                    :position="Position.Left"
                    :is-valid-connection="isValidConnection"
                    :style="{ backgroundColor: KIND_STYLE[node.kind as WorkflowKind].stroke }"
                  />

                  <div class="mb-1.5 flex items-center justify-between gap-1.5">
                    <div class="flex min-w-0 items-center gap-1.5">
                      <UIcon
                        :name="KIND_STYLE[node.kind as WorkflowKind].icon"
                        class="size-3.5 shrink-0"
                        :class="KIND_STYLE[node.kind as WorkflowKind].iconClass"
                      />
                      <span class="truncate text-[10px] font-bold uppercase tracking-wider text-muted">
                        {{ KIND_STYLE[node.kind as WorkflowKind].label }}
                      </span>
                    </div>

                    <div class="flex items-center gap-1">
                      <UButton
                        icon="i-lucide-info"
                        color="neutral"
                        variant="ghost"
                        size="xs"
                        class="-my-1 cursor-pointer text-muted hover:text-highlighted"
                        aria-label="View details"
                        @click.stop="openDetails(id)"
                        @pointerdown.stop
                        @mousedown.stop
                      />
                      <UButton
                        v-if="node.kind === 'group' && canWriteInSelectedProject"
                        icon="i-lucide-list-checks"
                        color="primary"
                        variant="ghost"
                        size="xs"
                        class="-my-1 cursor-pointer text-muted hover:text-primary"
                        :aria-label="`Select devices of ${node.label}`"
                        @click.stop="openGroup(node.entityId)"
                        @pointerdown.stop
                        @mousedown.stop
                      />
                      <UButton
                        v-if="unlinkPlan(id) !== null"
                        icon="i-lucide-unlink"
                        color="error"
                        variant="ghost"
                        size="xs"
                        class="-my-1 cursor-pointer text-muted hover:text-error"
                        :aria-label="`Remove ${node.label} from canvas`"
                        @click.stop="removeFromCanvas(id)"
                        @pointerdown.stop
                        @mousedown.stop
                      />
                    </div>
                  </div>

                  <div class="truncate text-sm font-semibold text-highlighted" :title="node.label">
                    {{ node.label }}
                  </div>

                  <div v-if="node.sublabel !== null" class="mt-1.5 truncate text-[11px] text-muted">
                    {{ node.sublabel }}
                  </div>

                  <Handle
                    v-if="node.kind !== 'webhook'"
                    type="source"
                    :position="Position.Right"
                    :is-valid-connection="isValidConnection"
                    :style="{ backgroundColor: KIND_STYLE[node.kind as WorkflowKind].stroke }"
                  />
                </div>
              </template>
            </VueFlow>
          </div>

          <template #fallback>
            <div class="flex h-[680px] items-center justify-center rounded-xl border border-default bg-elevated/20 text-sm text-muted">
              Preparing workflow...
            </div>
          </template>
        </ClientOnly>
      </div>

      <UModal
        v-model:open="detailsOpen"
        :title="detailsNode ? `${KIND_STYLE[detailsNode.data.kind].label} Details` : 'Details'"
        :description="detailsNode ? `Detailed information for ${detailsNode.data.label}.` : undefined"
      >
        <template #body>
          <div v-if="detailsNode" class="space-y-4">
            <div class="flex items-center gap-2.5 rounded-lg border border-default bg-elevated/50 p-3">
              <UIcon
                :name="KIND_STYLE[detailsNode.data.kind].icon"
                class="size-6 shrink-0"
                :class="KIND_STYLE[detailsNode.data.kind].iconClass"
              />
              <div class="min-w-0 flex-1">
                <div class="text-xs font-bold uppercase tracking-wider text-muted">
                  {{ KIND_STYLE[detailsNode.data.kind].label }}
                </div>
                <div class="truncate text-base font-semibold text-highlighted">
                  {{ detailsNode.data.label }}
                </div>
              </div>
            </div>

            <dl class="divide-y divide-default overflow-hidden rounded-lg border border-default text-sm">
              <div
                v-for="field in detailFields"
                :key="field.label"
                class="flex items-center justify-between gap-4 bg-elevated/20 px-4 py-2.5 even:bg-transparent"
              >
                <dt class="font-medium text-muted">
                  {{ field.label }}
                </dt>
                <dd class="break-all text-right font-medium text-highlighted">
                  <div v-if="Array.isArray(field.value)" class="flex flex-wrap justify-end gap-1.5">
                    <UBadge
                      v-for="item in field.value"
                      :key="item"
                      color="neutral"
                      variant="subtle"
                    >
                      {{ item }}
                    </UBadge>
                  </div>
                  <span v-else>{{ field.value }}</span>
                </dd>
              </div>
            </dl>

            <div class="flex justify-end gap-2 pt-2">
              <UButton
                label="Close"
                color="neutral"
                variant="subtle"
                @click="detailsOpen = false"
              />
              <UButton
                v-if="detailsNode.data.kind !== 'input' && (detailsNode.data.kind !== 'rule' || canWriteInSelectedProject)"
                :label="detailsNode.data.kind === 'rule' ? 'New revision' : 'Open page'"
                :icon="detailsNode.data.kind === 'rule' ? 'i-lucide-git-branch-plus' : 'i-lucide-external-link'"
                @click="openDetailsPage"
              />
            </div>
          </div>
        </template>
      </UModal>

      <UModal
        v-model:open="pendingOpen"
        :title="pending?.title"
        :description="pending?.description"
      >
        <template #body>
          <div class="flex justify-end gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="subtle"
              :disabled="pendingLoading"
              @click="pendingOpen = false"
            />
            <UButton
              label="Confirm"
              :loading="pendingLoading"
              @click="runPending"
            />
          </div>
        </template>
      </UModal>

      <template v-if="selectedProject !== null">
        <AlertingDeviceGroupModal
          v-model:open="groupFormOpen"
          :devices="source.devices"
          :profiles="source.profiles"
          :version-id="groupVersionId"
          :schema-version="groupVersionId === null ? null : profileByVersion.get(groupVersionId)?.versions.find(version => version.id === groupVersionId)?.version ?? null"
          :initial-device-ids="groupInitialIds"
          :title="groupTitle"
          :can-create="canWriteInSelectedProject"
          :loading="formLoading"
          @draft="saveDraft"
          @submit="saveGroup"
          @create="onGroupCreate"
        />

        <AlertingWorkflowPickerModal
          v-model:open="pickFormOpen"
          :title="pickTitle"
          :items="pickItems"
          :parent-items="pickParentItems"
          parent-label="Profile"
          item-label="Version"
          :can-create="pickCanCreate"
          @pick="onPick"
          @create="onPickCreate"
        />

        <AdminResourceFormModal
          v-model:open="deviceFormOpen"
          v-model:project-id="formProjectId"
          v-model:equipment-asset-id="formEquipmentAssetId"
          v-model:device-profile-version-id="formDeviceProfileVersionId"
          resource-type="device"
          :project-options="formProjectOptions"
          :equipment-asset-options="[{ label: 'No equipment asset', value: null }]"
          :device-profiles="source.profiles"
          :loading="formLoading"
          @submit-device="saveDevice"
        />

        <AdminDeviceProfileFormModal
          v-model:open="profileFormOpen"
          v-model:project-id="formProjectId"
          :project-options="formProjectOptions"
          :loading="formLoading"
          @submit="saveProfile"
        />

        <AlertingAlertRuleFormModal
          v-model:open="ruleFormOpen"
          :rule="editingRule"
          :project-options="formProjectOptions"
          :profiles="source.profiles"
          :loading="formLoading"
          @submit="saveRule"
        />

        <AlertingWebhookEndpointFormModal
          v-model:open="webhookFormOpen"
          :project-options="formProjectOptions"
          :loading="formLoading"
          @submit="saveWebhook"
        />
      </template>

      <AdminDeviceCredentialsModal
        v-model:open="credentialsOpen"
        :tenant-id="selectedProject?.tenant_id ?? null"
        :device="credentialsDevice"
        :can-manage="canWriteInSelectedProject && credentialsDevice?.approval_status === 'approved'"
      />

      <AlertingWebhookSecretModal
        v-model:open="secretOpen"
        :secret="revealedSecret"
      />
    </template>
  </UDashboardPanel>
</template>

<style scoped>
:deep(.vue-flow__handle) {
  width: 12px;
  height: 12px;
  border: 2px solid var(--ui-bg, #fff);
  box-shadow: 0 0 0 3px rgb(148 163 184 / 0.25);
}

:deep(.vue-flow__edge-path) {
  stroke-linecap: round;
  transition: stroke 150ms, stroke-width 150ms;
}

:deep(.workflow-edge-removable) {
  cursor: pointer;
}

:deep(.workflow-edge-removable:hover .vue-flow__edge-path) {
  stroke: var(--ui-error) !important;
  stroke-width: 3.5 !important;
}
</style>
