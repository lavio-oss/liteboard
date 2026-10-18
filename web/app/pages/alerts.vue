<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useAlerting } from '~/composables/useAlerting'
import { useAlertingProject } from '~/composables/useAlertingProject'
import type { Alert, AlertRule } from '~/types/alerting'

definePageMeta({
  middleware: 'auth'
})

// Alerts have no name of their own; the rule name and device name make them searchable in AdminTable.
type AlertRow = Alert & { name: string, code: string }

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const SeverityBadge = resolveComponent('AlertingSeverityBadge')
const toast = useToast()
const { loadDevices } = useAdmin()
const alerting = useAlerting()
const {
  canWriteInSelectedProject,
  notifyError,
  projectSelectOptions,
  projects,
  projectsError,
  projectsStatus,
  requireProject,
  selectedProject,
  selectedProjectId,
  sortableHeader
} = await useAlertingProject()

const { data, status, error, refresh } = await useAsyncData(
  'liteboard-alert-log',
  async () => {
    const project = selectedProject.value

    if (project === null) {
      return {
        alerts: [] as Alert[],
        devices: [] as Awaited<ReturnType<typeof loadDevices>>,
        rules: [] as AlertRule[]
      }
    }

    const [alerts, rules, devices] = await Promise.all([
      alerting.loadAlerts(project.tenant_id, project.id, { state: null }),
      alerting.loadAlertRules(project.tenant_id, project.id),
      loadDevices(project.tenant_id, project.id)
    ])

    return { alerts, devices, rules }
  },
  { watch: [selectedProjectId] }
)

const deviceNames = computed(() => new Map((data.value?.devices ?? []).map(device => [device.id, device.name])))
const rulesById = computed(() => new Map((data.value?.rules ?? []).map(rule => [rule.id, rule])))
const alertRows = computed<AlertRow[]>(() => (data.value?.alerts ?? []).map(alert => ({
  ...alert,
  name: rulesById.value.get(alert.alert_rule_id)?.name ?? '—',
  code: deviceNames.value.get(alert.device_id) ?? alert.device_id
})))

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

const errorMessage = computed(() => {
  if (projectsError.value instanceof ApiError) {
    return projectsError.value.message
  }

  if (error.value instanceof ApiError) {
    return error.value.message
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
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="projectsStatus === 'error' || status === 'error'"
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

        <AdminTable
          v-else
          :columns="alertColumns"
          :data="alertRows"
          :loading="projectsStatus === 'pending' || status === 'pending'"
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
    </template>
  </UDashboardPanel>
</template>
