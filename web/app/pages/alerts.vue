<script setup lang="ts">
import type { NavigationMenuItem, TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useAlerting } from '~/composables/useAlerting'
import { useTenantContext } from '~/composables/useTenantContext'
import type { DeviceProfile } from '~/types/admin'
import type { Alert, AlertRule, AlertRuleInput, WebhookDelivery, WebhookEndpoint, WebhookEndpointInput } from '~/types/alerting'
import { describeAlertRule } from '~/utils/alertRuleDefinition'
import { canDeleteProjectData, canWriteProjectData } from '~/utils/projectPermissions'

definePageMeta({
  middleware: 'auth'
})

type Section = 'alerts' | 'rules' | 'webhooks'
// Alerts have no name of their own; the rule name and device name make them searchable in AdminTable.
type AlertRow = Alert & { name: string, code: string }

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const SeverityBadge = resolveComponent('AlertingSeverityBadge')
const toast = useToast()
const { copy, copied } = useClipboard()
const { isPlatformAdmin, loadTenants, tenants } = useTenantContext()
const { loadDeviceProfiles, loadDevices, loadProjects } = useAdmin()
const alerting = useAlerting()
await loadTenants()

const tenantIds = computed(() => tenants.value.map(tenant => tenant.id))
const manageableTenantIds = computed(() => new Set(
  tenants.value
    .filter(tenant => isPlatformAdmin.value || tenant.role === 'owner' || tenant.role === 'admin')
    .map(tenant => tenant.id)
))

const { data: projectsData, status: projectsStatus, error: projectsError } = await useAsyncData(
  'liteboard-alerting-projects',
  () => Promise.all(tenants.value.map(tenant => loadProjects(tenant.id, { participating: true })))
    .then(lists => lists.flat()),
  { watch: [tenantIds] }
)

const projects = computed(() => projectsData.value ?? [])
const selectedProjectId = ref<string | undefined>(undefined)

watch(projects, (nextProjects) => {
  if (selectedProjectId.value === undefined || !nextProjects.some(project => project.id === selectedProjectId.value)) {
    selectedProjectId.value = nextProjects[0]?.id
  }
}, { immediate: true })

const selectedProject = computed(() => projects.value.find(project => project.id === selectedProjectId.value) ?? null)
const projectSelectOptions = computed(() => projects.value.map(project => ({
  label: project.name,
  value: project.id
})))
const formProjectOptions = computed(() => selectedProject.value === null
  ? []
  : [{ label: selectedProject.value.name, value: selectedProject.value.id }])

const canManageTenant = computed(() => selectedProject.value !== null
  && (isPlatformAdmin.value || manageableTenantIds.value.has(selectedProject.value.tenant_id)))
// Rules and acknowledgement need Admin/Engineer; webhook changes, secrets and redelivery need Admin.
const canWriteInSelectedProject = computed(() => selectedProject.value !== null
  && canWriteProjectData(selectedProject.value.role, canManageTenant.value))
const canAdminInSelectedProject = computed(() => selectedProject.value !== null
  && canDeleteProjectData(selectedProject.value.role, canManageTenant.value))

const section = ref<Section>('alerts')

const sectionLinks = computed<NavigationMenuItem[][]>(() => [[
  {
    label: 'Alert log',
    icon: 'i-lucide-bell-ring',
    active: section.value === 'alerts',
    onSelect: () => {
      section.value = 'alerts'
    }
  },
  {
    label: 'Rules',
    icon: 'i-lucide-list-checks',
    active: section.value === 'rules',
    onSelect: () => {
      section.value = 'rules'
    }
  },
  ...(canWriteInSelectedProject.value
    ? [{
        label: 'Webhooks',
        icon: 'i-lucide-webhook',
        active: section.value === 'webhooks',
        onSelect: () => {
          section.value = 'webhooks'
        }
      }]
    : [])
]])

watch(canWriteInSelectedProject, (canWrite) => {
  if (!canWrite && section.value === 'webhooks') {
    section.value = 'alerts'
  }
})

const { data: alertingData, status: alertingStatus, error: alertingError, refresh } = await useAsyncData(
  'liteboard-alerting',
  async () => {
    const project = selectedProject.value

    if (project === null) {
      return {
        alerts: [] as Alert[],
        rules: [] as AlertRule[],
        endpoints: [] as WebhookEndpoint[],
        devices: [] as Awaited<ReturnType<typeof loadDevices>>,
        profiles: [] as DeviceProfile[]
      }
    }

    const [alerts, rules, endpoints, devices, profiles] = await Promise.all([
      alerting.loadAlerts(project.tenant_id, project.id, { state: null }),
      alerting.loadAlertRules(project.tenant_id, project.id),
      canWriteInSelectedProject.value ? alerting.loadWebhookEndpoints(project.tenant_id, project.id) : Promise.resolve([]),
      loadDevices(project.tenant_id, project.id),
      loadDeviceProfiles(project.tenant_id, project.id)
    ])

    return { alerts, rules, endpoints, devices, profiles }
  },
  { watch: [selectedProjectId] }
)

const rules = computed(() => alertingData.value?.rules ?? [])
const endpoints = computed(() => alertingData.value?.endpoints ?? [])
const profiles = computed(() => alertingData.value?.profiles ?? [])
const deviceNames = computed(() => new Map((alertingData.value?.devices ?? []).map(device => [device.id, device.name])))
const rulesById = computed(() => new Map(rules.value.map(rule => [rule.id, rule])))
const alertRows = computed<AlertRow[]>(() => (alertingData.value?.alerts ?? []).map(alert => ({
  ...alert,
  name: rulesById.value.get(alert.alert_rule_id)?.name ?? '—',
  code: deviceNames.value.get(alert.device_id) ?? alert.device_id
})))

function requireProject() {
  if (selectedProject.value === null) {
    throw new Error('A project is required.')
  }

  return selectedProject.value
}

function notifyError(cause: unknown, title: string): void {
  if (!(cause instanceof ApiError)) {
    throw cause
  }

  toast.add({ title, description: Object.values(cause.errors).flat()[0] ?? cause.message, color: 'error' })
}

function sortableHeader(label: string) {
  return ({ column }: { column: { getIsSorted: () => false | 'asc' | 'desc', toggleSorting: (desc?: boolean) => void } }) => {
    const isSorted = column.getIsSorted()

    return h(UButton, {
      color: 'neutral',
      variant: 'ghost',
      label,
      icon: isSorted
        ? isSorted === 'asc'
          ? 'i-lucide-arrow-up-narrow-wide'
          : 'i-lucide-arrow-down-wide-narrow'
        : 'i-lucide-arrow-up-down',
      class: '-mx-2.5',
      onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
    })
  }
}

// Alerts
const viewOpen = ref(false)
const viewLoading = ref(false)
const viewingAlert = ref<Alert | null>(null)

async function openView(alert: Alert): Promise<void> {
  const project = requireProject()

  viewingAlert.value = alert
  viewOpen.value = true
  viewLoading.value = true

  try {
    viewingAlert.value = await alerting.loadAlert(project.tenant_id, project.id, alert.id)
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to load alert history')
  } finally {
    viewLoading.value = false
  }
}

async function acknowledge(alert: Alert): Promise<void> {
  const project = requireProject()
  viewLoading.value = true

  try {
    await alerting.acknowledgeAlert(project.tenant_id, project.id, alert.id)
    if (viewingAlert.value?.id === alert.id) {
      viewingAlert.value = await alerting.loadAlert(project.tenant_id, project.id, alert.id)
    }
    await refresh()
    toast.add({ title: 'Alert acknowledged', description: 'The alert stays active until it recovers.', color: 'success' })
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to acknowledge alert')
  } finally {
    viewLoading.value = false
  }
}

const alertColumns: TableColumn<AlertRow>[] = [
  {
    id: 'sequence',
    header: 'No.',
    cell: ({ row }) => row.index + 1
  },
  {
    id: 'severity',
    accessorKey: 'severity',
    header: sortableHeader('Severity'),
    cell: ({ row }) => h(SeverityBadge, { severity: row.original.severity })
  },
  {
    id: 'name',
    accessorKey: 'name',
    header: 'Rule',
    cell: ({ row }) => h('span', { class: 'font-medium text-highlighted' }, row.original.name)
  },
  {
    id: 'device',
    accessorKey: 'code',
    header: 'Device'
  },
  {
    id: 'value',
    header: 'Value',
    cell: ({ row }) => {
      const { field, value } = row.original.evidence

      return typeof field === 'string' ? `${field} = ${String(value)}` : '—'
    }
  },
  {
    id: 'state',
    header: 'Status',
    cell: ({ row }) => h('div', { class: 'flex gap-1' }, [
      h(
        UBadge,
        { class: 'capitalize', color: row.original.cleared_at === null ? 'error' : 'success', variant: 'subtle' },
        () => row.original.cleared_at === null ? 'active' : 'cleared'
      ),
      row.original.acknowledged_at === null
        ? null
        : h(UBadge, { color: 'info', variant: 'outline' }, () => 'Ack')
    ])
  },
  {
    id: 'opened_at',
    accessorKey: 'opened_at',
    header: sortableHeader('Opened'),
    cell: ({ row }) => formatDateTime(row.original.opened_at)
  },
  {
    id: 'last_seen_at',
    accessorKey: 'last_seen_at',
    header: sortableHeader('Last seen'),
    cell: ({ row }) => formatDateTime(row.original.last_seen_at)
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const buttons = [
        h(UButton, {
          'icon': 'i-lucide-eye',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `View ${row.original.name}`,
          'onClick': () => openView(row.original)
        })
      ]

      if (canWriteInSelectedProject.value && row.original.acknowledged_at === null) {
        buttons.push(h(UButton, {
          'icon': 'i-lucide-check',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `Acknowledge ${row.original.name}`,
          'onClick': () => acknowledge(row.original)
        }))
      }

      return h('div', { class: 'flex justify-end gap-1' }, buttons)
    }
  }
]

// Rules
const ruleFormOpen = ref(false)
const ruleFormLoading = ref(false)
const editingRule = ref<AlertRule | null>(null)
const archiveOpen = ref(false)
const archiveLoading = ref(false)
const archivingRule = ref<AlertRule | null>(null)

function openRuleCreate(): void {
  editingRule.value = null
  ruleFormOpen.value = true
}

function openRuleRevision(ruleId: string): void {
  const rule = rules.value.find(item => item.id === ruleId)

  if (rule === undefined) {
    throw new Error('The selected rule is not available.')
  }

  editingRule.value = rule
  ruleFormOpen.value = true
}

function openArchive(ruleId: string): void {
  const rule = rules.value.find(item => item.id === ruleId)

  if (rule === undefined) {
    throw new Error('The selected rule is not available.')
  }

  archivingRule.value = rule
  archiveOpen.value = true
}

async function saveRule({ input }: { projectId: string, input: AlertRuleInput }): Promise<void> {
  const project = requireProject()
  const isCreate = editingRule.value === null
  ruleFormLoading.value = true

  try {
    if (editingRule.value === null) {
      await alerting.createAlertRule(project.tenant_id, project.id, input)
    } else {
      await alerting.createAlertRuleRevision(project.tenant_id, project.id, editingRule.value.id, input.definition)
    }

    await refresh()
    ruleFormOpen.value = false
    toast.add({
      title: isCreate ? 'Rule created' : 'Revision created',
      description: 'The rule applies to telemetry received from now on.',
      color: 'success'
    })
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to save rule')
  } finally {
    ruleFormLoading.value = false
  }
}

async function archiveSelectedRule(): Promise<void> {
  const project = requireProject()

  if (archivingRule.value === null) {
    throw new Error('A rule is required before archiving.')
  }

  archiveLoading.value = true

  try {
    await alerting.archiveAlertRule(project.tenant_id, project.id, archivingRule.value.id)
    await refresh()
    archiveOpen.value = false
    toast.add({ title: 'Rule archived', description: 'Its active alerts were cleared.', color: 'success' })
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to archive rule')
  } finally {
    archiveLoading.value = false
  }
}

const ruleColumns: TableColumn<AlertRule>[] = [
  {
    id: 'sequence',
    header: 'No.',
    cell: ({ row }) => row.index + 1
  },
  {
    id: 'name',
    accessorKey: 'name',
    header: 'Name',
    cell: ({ row }) => h('span', { class: 'font-medium text-highlighted' }, row.original.name)
  },
  {
    id: 'code',
    accessorKey: 'code',
    header: 'Code'
  },
  {
    id: 'condition',
    header: 'Condition',
    cell: ({ row }) => {
      const definition = row.original.current_revision?.definition

      return definition === undefined ? '—' : describeAlertRule(definition)
    }
  },
  {
    id: 'severity',
    header: 'Severity',
    cell: ({ row }) => {
      const severity = row.original.current_revision?.definition.severity

      return severity === undefined ? '—' : h(SeverityBadge, { severity })
    }
  },
  {
    id: 'profile',
    header: 'Profile',
    cell: ({ row }) => profiles.value.find(profile => profile.id === row.original.device_profile_id)?.name ?? '—'
  },
  {
    id: 'revision',
    header: 'Revision',
    cell: ({ row }) => `v${row.original.current_revision?.revision ?? '—'}`
  },
  {
    id: 'status',
    accessorKey: 'status',
    header: sortableHeader('Status'),
    cell: ({ row }) => h(
      UBadge,
      { class: 'capitalize', color: row.original.status === 'active' ? 'success' : 'neutral', variant: 'subtle' },
      () => row.original.status
    )
  },
  {
    id: 'created_at',
    accessorKey: 'created_at',
    header: sortableHeader('Created'),
    cell: ({ row }) => formatDateTime(row.original.created_at)
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      if (row.original.status !== 'active' || !canWriteInSelectedProject.value) {
        return null
      }

      return h('div', { class: 'flex justify-end gap-1' }, [
        h(UButton, {
          'icon': 'i-lucide-git-branch-plus',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `New revision of ${row.original.name}`,
          'onClick': () => openRuleRevision(row.original.id)
        }),
        h(UButton, {
          'icon': 'i-lucide-archive',
          'color': 'error',
          'variant': 'ghost',
          'aria-label': `Archive ${row.original.name}`,
          'onClick': () => openArchive(row.original.id)
        })
      ])
    }
  }
]

// Webhooks
const endpointFormOpen = ref(false)
const endpointFormLoading = ref(false)
const editingEndpoint = ref<WebhookEndpoint | null>(null)
const secretOpen = ref(false)
const revealedSecret = ref('')
const rotateOpen = ref(false)
const rotateLoading = ref(false)
const rotatingEndpoint = ref<WebhookEndpoint | null>(null)
const deliveriesOpen = ref(false)
const deliveriesLoading = ref(false)
const deliveriesEndpoint = ref<WebhookEndpoint | null>(null)
const deliveries = ref<WebhookDelivery[]>([])
const redeliveringId = ref<string | null>(null)

function showSecret(secret: string): void {
  revealedSecret.value = secret
  secretOpen.value = true
}

watch(secretOpen, (isOpen) => {
  if (!isOpen) {
    revealedSecret.value = ''
  }
})

function openEndpointForm(endpointId: string | null): void {
  const endpoint = endpointId === null ? null : endpoints.value.find(item => item.id === endpointId)

  if (endpoint === undefined) {
    throw new Error('The selected endpoint is not available.')
  }

  editingEndpoint.value = endpoint
  endpointFormOpen.value = true
}

function openRotate(endpointId: string): void {
  const endpoint = endpoints.value.find(item => item.id === endpointId)

  if (endpoint === undefined) {
    throw new Error('The selected endpoint is not available.')
  }

  rotatingEndpoint.value = endpoint
  rotateOpen.value = true
}

async function saveEndpoint({ input }: { projectId: string, input: WebhookEndpointInput }): Promise<void> {
  const project = requireProject()
  const isCreate = editingEndpoint.value === null
  endpointFormLoading.value = true

  try {
    if (editingEndpoint.value === null) {
      showSecret((await alerting.createWebhookEndpoint(project.tenant_id, project.id, input)).secret)
    } else {
      await alerting.updateWebhookEndpoint(project.tenant_id, project.id, editingEndpoint.value.id, input)
    }

    await refresh()
    endpointFormOpen.value = false
    if (!isCreate) {
      toast.add({ title: 'Endpoint updated', description: 'The webhook endpoint has been saved.', color: 'success' })
    }
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to save webhook endpoint')
  } finally {
    endpointFormLoading.value = false
  }
}

async function toggleEndpoint(endpoint: WebhookEndpoint): Promise<void> {
  const project = requireProject()

  try {
    await alerting.updateWebhookEndpoint(project.tenant_id, project.id, endpoint.id, { enabled: !endpoint.enabled })
    await refresh()
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to update webhook endpoint')
  }
}

async function rotateSelectedSecret(): Promise<void> {
  const project = requireProject()

  if (rotatingEndpoint.value === null) {
    throw new Error('An endpoint is required before rotating its secret.')
  }

  rotateLoading.value = true

  try {
    const rotated = await alerting.rotateWebhookSecret(project.tenant_id, project.id, rotatingEndpoint.value.id)
    await refresh()
    rotateOpen.value = false
    showSecret(rotated.secret)
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to rotate secret')
  } finally {
    rotateLoading.value = false
  }
}

async function loadDeliveries(): Promise<void> {
  const project = requireProject()

  if (deliveriesEndpoint.value === null) {
    return
  }

  deliveriesLoading.value = true

  try {
    deliveries.value = await alerting.loadWebhookDeliveries(project.tenant_id, project.id, deliveriesEndpoint.value.id)
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to load deliveries')
  } finally {
    deliveriesLoading.value = false
  }
}

async function openDeliveries(endpointId: string): Promise<void> {
  const endpoint = endpoints.value.find(item => item.id === endpointId)

  if (endpoint === undefined) {
    throw new Error('The selected endpoint is not available.')
  }

  deliveriesEndpoint.value = endpoint
  deliveries.value = []
  deliveriesOpen.value = true
  await loadDeliveries()
}

async function redeliver(deliveryId: string): Promise<void> {
  const project = requireProject()

  if (deliveriesEndpoint.value === null) {
    throw new Error('An endpoint is required before redelivering.')
  }

  redeliveringId.value = deliveryId

  try {
    await alerting.redeliverWebhookDelivery(project.tenant_id, project.id, deliveriesEndpoint.value.id, deliveryId)
    toast.add({ title: 'Delivery queued', description: 'The dispatcher will resend it with the same event id.', color: 'success' })
    await loadDeliveries()
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to redeliver')
  } finally {
    redeliveringId.value = null
  }
}

const endpointColumns: TableColumn<WebhookEndpoint>[] = [
  {
    id: 'sequence',
    header: 'No.',
    cell: ({ row }) => row.index + 1
  },
  {
    id: 'name',
    accessorKey: 'name',
    header: 'Name',
    cell: ({ row }) => h('span', { class: 'font-medium text-highlighted' }, row.original.name)
  },
  {
    id: 'url',
    accessorKey: 'url',
    header: 'URL',
    cell: ({ row }) => h('span', { class: 'font-mono text-xs break-all' }, row.original.url)
  },
  {
    id: 'events',
    header: 'Events',
    cell: ({ row }) => h('div', { class: 'flex flex-wrap gap-1' }, row.original.event_types.map(type => h(
      UBadge,
      { color: 'neutral', variant: 'subtle' },
      () => type
    )))
  },
  {
    id: 'enabled',
    accessorKey: 'enabled',
    header: sortableHeader('Status'),
    cell: ({ row }) => h(
      UBadge,
      { class: 'capitalize', color: row.original.enabled ? 'success' : 'neutral', variant: 'subtle' },
      () => row.original.enabled ? 'enabled' : 'disabled'
    )
  },
  {
    id: 'secret_rotated_at',
    accessorKey: 'secret_rotated_at',
    header: sortableHeader('Secret rotated'),
    cell: ({ row }) => formatDateTime(row.original.secret_rotated_at)
  },
  {
    id: 'created_at',
    accessorKey: 'created_at',
    header: sortableHeader('Created'),
    cell: ({ row }) => formatDateTime(row.original.created_at)
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const buttons = [
        h(UButton, {
          'icon': 'i-lucide-history',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `Deliveries of ${row.original.name}`,
          'onClick': () => openDeliveries(row.original.id)
        })
      ]

      if (canAdminInSelectedProject.value) {
        buttons.push(
          h(UButton, {
            'icon': row.original.enabled ? 'i-lucide-pause' : 'i-lucide-play',
            'color': 'neutral',
            'variant': 'ghost',
            'aria-label': `${row.original.enabled ? 'Disable' : 'Enable'} ${row.original.name}`,
            'onClick': () => toggleEndpoint(row.original)
          }),
          h(UButton, {
            'icon': 'i-lucide-pencil',
            'color': 'neutral',
            'variant': 'ghost',
            'aria-label': `Edit ${row.original.name}`,
            'onClick': () => openEndpointForm(row.original.id)
          }),
          h(UButton, {
            'icon': 'i-lucide-key-round',
            'color': 'warning',
            'variant': 'ghost',
            'aria-label': `Rotate secret of ${row.original.name}`,
            'onClick': () => openRotate(row.original.id)
          })
        )
      }

      return h('div', { class: 'flex justify-end gap-1' }, buttons)
    }
  }
]

const errorMessage = computed(() => {
  if (projectsError.value instanceof ApiError) {
    return projectsError.value.message
  }

  if (alertingError.value instanceof ApiError) {
    return alertingError.value.message
  }

  return 'The alerts could not be loaded.'
})
</script>

<template>
  <UDashboardPanel id="liteboard-alerts" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Alerts">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="section === 'rules' && canWriteInSelectedProject"
            label="Add rule"
            icon="i-lucide-plus"
            @click="openRuleCreate"
          />
          <UButton
            v-if="section === 'webhooks' && canAdminInSelectedProject"
            label="Add endpoint"
            icon="i-lucide-plus"
            @click="openEndpointForm(null)"
          />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <UNavigationMenu :items="sectionLinks" highlight class="-mx-1 flex-1" />
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="projectsStatus === 'error' || alertingStatus === 'error'"
          color="error"
          variant="subtle"
          title="Unable to load alerts"
          :description="errorMessage"
        />

        <UAlert
          v-if="projectsStatus !== 'pending' && projects.length === 0"
          color="warning"
          variant="subtle"
          title="No project assigned"
          description="Your account is not currently assigned to any project. Contact an administrator to request access."
        />

        <template v-else>
          <AdminTable
            v-if="section === 'alerts'"
            :columns="alertColumns"
            :data="alertRows"
            :loading="projectsStatus === 'pending' || alertingStatus === 'pending'"
            :show-status-filter="false"
            search-placeholder="Search alerts..."
          >
            <template #search-trailing>
              <USelect
                v-if="projects.length > 1"
                v-model="selectedProjectId"
                :items="projectSelectOptions"
                class="min-w-44"
                :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
              />
            </template>
          </AdminTable>

          <AdminTable
            v-else-if="section === 'rules'"
            :columns="ruleColumns"
            :data="rules"
            :loading="projectsStatus === 'pending' || alertingStatus === 'pending'"
            :show-status-filter="false"
            search-placeholder="Search rules..."
          >
            <template #search-trailing>
              <USelect
                v-if="projects.length > 1"
                v-model="selectedProjectId"
                :items="projectSelectOptions"
                class="min-w-44"
                :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
              />
            </template>
          </AdminTable>

          <AdminTable
            v-else
            :columns="endpointColumns"
            :data="endpoints"
            :loading="projectsStatus === 'pending' || alertingStatus === 'pending'"
            :show-status-filter="false"
            search-placeholder="Search endpoints..."
          >
            <template #search-trailing>
              <USelect
                v-if="projects.length > 1"
                v-model="selectedProjectId"
                :items="projectSelectOptions"
                class="min-w-44"
                :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
              />
            </template>
          </AdminTable>
        </template>
      </div>

      <AlertingAlertDetailModal
        v-model:open="viewOpen"
        :alert="viewingAlert"
        :device-name="viewingAlert === null ? '' : deviceNames.get(viewingAlert.device_id) ?? viewingAlert.device_id"
        :rule-name="viewingAlert === null ? 'Alert' : rulesById.get(viewingAlert.alert_rule_id)?.name ?? 'Alert'"
        :project-name="selectedProject?.name"
        :can-acknowledge="canWriteInSelectedProject"
        :loading="viewLoading"
        @acknowledge="viewingAlert !== null && acknowledge(viewingAlert)"
      />

      <AlertingAlertRuleFormModal
        v-if="selectedProject !== null"
        v-model:open="ruleFormOpen"
        :rule="editingRule"
        :project-options="formProjectOptions"
        :profiles="profiles"
        :loading="ruleFormLoading"
        @submit="saveRule"
      />

      <ConfirmDeleteModal
        v-model:open="archiveOpen"
        :title="`Archive ${archivingRule?.name ?? 'rule'}`"
        description="The rule stops evaluating and its active alerts are cleared as rule_archived. This cannot be undone."
        :loading="archiveLoading"
        @confirm="archiveSelectedRule"
      />

      <AlertingWebhookEndpointFormModal
        v-if="selectedProject !== null"
        v-model:open="endpointFormOpen"
        :endpoint="editingEndpoint"
        :project-options="formProjectOptions"
        :loading="endpointFormLoading"
        @submit="saveEndpoint"
      />

      <UModal
        v-model:open="rotateOpen"
        :title="`Rotate secret of ${rotatingEndpoint?.name ?? 'endpoint'}`"
        description="The old secret stops working immediately. Update the receiver before the next delivery."
      >
        <template #body>
          <div class="flex justify-end gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="subtle"
              :disabled="rotateLoading"
              @click="rotateOpen = false"
            />
            <UButton
              label="Rotate"
              color="warning"
              :loading="rotateLoading"
              @click="rotateSelectedSecret"
            />
          </div>
        </template>
      </UModal>

      <UModal
        v-model:open="secretOpen"
        title="Signing secret"
        description="Copy it now. It is shown only once and cannot be retrieved later."
      >
        <template #body>
          <div class="space-y-4">
            <UInput
              :model-value="revealedSecret"
              readonly
              class="w-full font-mono"
            />
            <p class="text-xs text-muted">
              Verify requests with HMAC-SHA256 over <code>{timestamp}.{body}</code> and compare with
              <code>X-LiteBoard-Signature: v1=…</code>.
            </p>
            <div class="flex justify-end gap-2">
              <UButton
                :label="copied ? 'Copied' : 'Copy'"
                :icon="copied ? 'i-lucide-check' : 'i-lucide-copy'"
                color="neutral"
                variant="outline"
                @click="copy(revealedSecret)"
              />
              <UButton label="Done" @click="secretOpen = false" />
            </div>
          </div>
        </template>
      </UModal>

      <AlertingWebhookDeliveriesModal
        v-model:open="deliveriesOpen"
        :endpoint="deliveriesEndpoint"
        :deliveries="deliveries"
        :can-redeliver="canAdminInSelectedProject"
        :loading="deliveriesLoading"
        :redelivering-id="redeliveringId"
        @redeliver="redeliver"
        @refresh="loadDeliveries"
      />
    </template>
  </UDashboardPanel>
</template>
