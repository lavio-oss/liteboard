<script setup lang="ts">
import type { NavigationMenuItem, TableColumn } from '@nuxt/ui'
import { getPaginationRowModel } from '@tanstack/table-core'
import { VisAxis, VisDonut, VisLine, VisSingleContainer, VisXYContainer } from '@unovis/vue'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useAlerting } from '~/composables/useAlerting'
import { useTelemetry } from '~/composables/useTelemetry'
import { useDevicePresenceStream } from '~/composables/useDevicePresenceStream'
import type { TelemetryDeviceScope } from '~/composables/useTelemetry'
import { useTelemetryStream } from '~/composables/useTelemetryStream'
import { useTenantContext } from '~/composables/useTenantContext'
import { canWriteProjectData } from '~/utils/projectPermissions'
import type { DeviceInput } from '~/types/admin'
import type { Alert, AlertRule } from '~/types/alerting'
import DevicePresenceStatus from '~/components/realtime/DevicePresenceStatus.vue'
import TelemetryOutcomeStatus from '~/components/realtime/TelemetryOutcomeStatus.vue'
import type {
  TelemetryAggregate,
  TelemetryIngestOutcome,
  TelemetryInterval,
  TelemetryLatestValue,
  TelemetrySample,
  TelemetryTrace,
  DevicePresenceEventData
} from '~/types/telemetry'
import { telemetryOutcomeStatusPresentation } from '~/utils/realtimeStatus'
import { hasValidationErrors, mergeTelemetryOutcomes, summarizeOutcomes } from '~/utils/telemetryOutcomes'
import { applySampleToLatestValues, mergeTelemetrySamples } from '~/utils/telemetrySamples'
import { applyDevicePresenceToDevice, mergeDevicePresenceEvents } from '~/utils/devicePresence'

definePageMeta({
  middleware: 'auth'
})

const LIVE_SAMPLE_LIMIT = 500
const HISTORY_LIMIT = 1000
const RECOVERY_MAX_PAGES = 5
const PAGE_SIZE = 10
const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const USelect = resolveComponent('USelect')
const NuxtLink = resolveComponent('NuxtLink')
const tableUi = {
  base: 'border-separate border-spacing-0',
  thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
  tbody: '[&>tr]:last:[&>td]:border-b-0',
  th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
  td: 'border-b border-default',
  separator: 'h-0'
}
const OUTCOME_LIMIT = 50
// Devices may report up to five minutes ahead of the server (IT-3 clock-skew policy).
const FUTURE_SKEW_MS = 5 * 60 * 1000

const route = useRoute()
const toast = useToast()
const { activeTenantId, canManageActiveTenant, loadTenants } = useTenantContext()
// A direct load or refresh of this URL starts with no active tenant, so the device could not be fetched.
await loadTenants()
const { loadAssets, loadDevice, loadDeviceProfiles, loadDevices, loadProjects, updateDevice } = useAdmin()
const {
  loadLatestTelemetry,
  loadMessageOutcomes,
  loadMessageTrace,
  loadTelemetryAggregates,
  loadTelemetryHistory
} = useTelemetry()

const projectId = String(route.params.projectId)
const deviceId = String(route.params.deviceId)

function deviceScope(tenantId = activeTenantId.value): TelemetryDeviceScope {
  if (tenantId === null) {
    throw new Error('An active tenant is required to view device telemetry.')
  }

  return { tenantId, projectId, deviceId }
}

function notifyError(error: unknown, title: string): void {
  if (!(error instanceof ApiError)) {
    throw error
  }

  toast.add({ title, description: error.message, color: 'error' })
}

async function loadLatestSnapshot(tenantId: string): Promise<void> {
  try {
    const values = await loadLatestTelemetry(deviceScope(tenantId))

    if (activeTenantId.value === tenantId) {
      latest.value = values
    }
  } catch (error: unknown) {
    if (activeTenantId.value === tenantId) {
      notifyError(error, 'Latest telemetry could not be loaded')
    }
  }
}

const { data: deviceSnapshot, error: deviceError, refresh: refreshDevice } = await useAsyncData(
  `device-${deviceId}`,
  () => activeTenantId.value === null ? Promise.resolve(null) : loadDevice(activeTenantId.value, projectId, deviceId),
  { watch: [activeTenantId] }
)

// shortcut: filters the first 100 project devices client-side; add a gateway filter to the devices API for large gateways.
const { data: subDevices } = await useAsyncData(
  `device-sub-devices-${deviceId}`,
  async () => {
    const tenantId = activeTenantId.value

    if (tenantId === null || deviceSnapshot.value?.is_gateway !== true) {
      return []
    }

    return (await loadDevices(tenantId, projectId)).filter(item => item.gateway_device_id === deviceId)
  },
  { watch: [activeTenantId, () => deviceSnapshot.value?.is_gateway] }
)

// Everything the edit form needs; only fetched for users who may edit this device.
const { data: editContext } = await useAsyncData(
  `device-edit-context-${deviceId}`,
  async () => {
    const tenantId = activeTenantId.value

    if (tenantId === null) {
      return null
    }

    const project = (await loadProjects(tenantId)).find(item => item.id === projectId) ?? null

    if (project === null || !canWriteProjectData(project.role, canManageActiveTenant.value)) {
      return null
    }

    const [assets, deviceProfiles] = await Promise.all([
      loadAssets(tenantId, projectId),
      loadDeviceProfiles(tenantId, projectId)
    ])

    return { assets, deviceProfiles, project }
  },
  { watch: [activeTenantId] }
)

const editLoading = ref(false)
const equipmentAssetOptions = computed(() => [
  { label: 'No equipment asset', value: null },
  ...(editContext.value?.assets ?? [])
    .filter(asset => asset.asset_type === 'equipment')
    .map(asset => ({ label: `${asset.name} (${asset.code})`, value: asset.id }))
])

async function saveDevice(payload: DeviceInput): Promise<void> {
  const tenantId = activeTenantId.value

  if (tenantId === null) {
    throw new Error('An active tenant is required before saving a device.')
  }

  editLoading.value = true

  try {
    await updateDevice(tenantId, projectId, deviceId, payload)
    await refreshDevice()
    toast.add({ title: 'Device updated', description: 'The device has been saved.', color: 'success' })
  } catch (error: unknown) {
    notifyError(error, 'Unable to save device')
  } finally {
    editLoading.value = false
  }
}

const devicePresence = ref<DevicePresenceEventData | null>(null)
const device = computed(() => deviceSnapshot.value === null || deviceSnapshot.value === undefined
  ? null
  : applyDevicePresenceToDevice(deviceSnapshot.value, devicePresence.value))

function acceptDevicePresence(incoming: DevicePresenceEventData): void {
  if (incoming.device_id !== deviceId || incoming.project_id !== projectId) {
    return
  }

  devicePresence.value = mergeDevicePresenceEvents(devicePresence.value, incoming)
}

const presenceStream = useDevicePresenceStream(() => activeTenantId.value, {
  onPresence: acceptDevicePresence,
  onLive: async () => {
    await refreshDevice()
  }
})

// Alerts raised for this device. Writers (the ones who see Settings) may also acknowledge them.
const { acknowledgeAlert, loadAlert, loadAlertRules, loadAlerts } = useAlerting()
const { data: alertsData, status: alertsStatus, refresh: refreshAlerts } = await useAsyncData(
  `device-alerts-${deviceId}`,
  async () => {
    const tenantId = activeTenantId.value

    if (tenantId === null) {
      return { alerts: [] as Alert[], rules: [] as AlertRule[] }
    }

    const [alerts, rules] = await Promise.all([
      loadAlerts(tenantId, projectId, { state: null, deviceId }),
      loadAlertRules(tenantId, projectId)
    ])

    return { alerts, rules }
  },
  { watch: [activeTenantId] }
)
const deviceAlerts = computed(() => alertsData.value?.alerts ?? [])
const alertRuleNames = computed(() => new Map((alertsData.value?.rules ?? []).map(rule => [rule.id, rule.name])))
const activeAlertCount = computed(() => deviceAlerts.value.filter(alert => alert.cleared_at === null).length)
const SeverityBadge = resolveComponent('AlertingSeverityBadge')
// AdminTable searches by name, so each row carries its rule name.
type AlertRow = Alert & { name: string }
const sortableHeader = useSortableHeader()
const alertRows = computed<AlertRow[]>(() => deviceAlerts.value.map(alert => ({
  ...alert,
  name: alertRuleNames.value.get(alert.alert_rule_id) ?? '—'
})))
const alertViewOpen = ref(false)
const alertViewLoading = ref(false)
const viewingAlert = ref<Alert | null>(null)

async function openAlert(alert: Alert): Promise<void> {
  const tenantId = activeTenantId.value

  if (tenantId === null) {
    return
  }

  viewingAlert.value = alert
  alertViewOpen.value = true
  alertViewLoading.value = true

  try {
    viewingAlert.value = await loadAlert(tenantId, projectId, alert.id)
  } catch (error: unknown) {
    notifyError(error, 'Unable to load alert history')
  } finally {
    alertViewLoading.value = false
  }
}

async function acknowledge(alert: Alert): Promise<void> {
  const tenantId = activeTenantId.value

  if (tenantId === null) {
    return
  }

  alertViewLoading.value = true

  try {
    await acknowledgeAlert(tenantId, projectId, alert.id)
    if (viewingAlert.value?.id === alert.id) {
      viewingAlert.value = await loadAlert(tenantId, projectId, alert.id)
    }
    await refreshAlerts()
    toast.add({ title: 'Alert acknowledged', description: 'The alert stays active until it recovers.', color: 'success' })
  } catch (error: unknown) {
    notifyError(error, 'Unable to acknowledge alert')
  } finally {
    alertViewLoading.value = false
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
      row.original.acknowledged_at === null ? null : h(UBadge, { color: 'info', variant: 'outline' }, () => 'Ack')
    ])
  },
  {
    id: 'opened_at',
    accessorKey: 'opened_at',
    header: sortableHeader('Opened'),
    cell: ({ row }) => formatDateTime(row.original.opened_at)
  },
  {
    id: 'cleared_at',
    accessorKey: 'cleared_at',
    header: sortableHeader('Cleared'),
    cell: ({ row }) => formatDateTime(row.original.cleared_at)
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
          'aria-label': 'View alert',
          'onClick': () => openAlert(row.original)
        })
      ]

      if (editContext.value && row.original.acknowledged_at === null) {
        buttons.push(h(UButton, {
          'icon': 'i-lucide-check',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': 'Acknowledge alert',
          'onClick': () => acknowledge(row.original)
        }))
      }

      return h('div', { class: 'flex justify-end gap-1' }, buttons)
    }
  }
]

// Same tab bar as the devices list; the active tab lives in the URL.
const tabDefinitions = computed(() => [
  { value: 'overview', label: 'Overview', icon: 'i-lucide-gauge' },
  // A gateway stores no samples of its own; its sub-devices' metrics are read on their own pages.
  ...(device.value?.is_gateway ? [] : [{ value: 'telemetry', label: 'Telemetry', icon: 'i-lucide-activity' }]),
  { value: 'alerts', label: 'Alerts', icon: 'i-lucide-bell', badge: activeAlertCount.value > 0 ? activeAlertCount.value : undefined },
  { value: 'inspector', label: 'Message Inspector', icon: 'i-lucide-scroll-text' },
  ...(device.value?.is_gateway ? [{ value: 'sub-devices', label: 'Sub-devices', icon: 'i-lucide-network', badge: subDevices.value?.length }] : []),
  ...(editContext.value ? [{ value: 'settings', label: 'Settings', icon: 'i-lucide-settings' }] : [])
])
const activeTab = computed(() => tabDefinitions.value.find(tab => tab.value === route.query.tab)?.value ?? 'overview')
const tabs = computed<NavigationMenuItem[][]>(() => [tabDefinitions.value.map(tab => ({
  ...tab,
  to: { query: tab.value === 'overview' ? {} : { tab: tab.value } },
  active: activeTab.value === tab.value
}))])

const latest = ref<TelemetryLatestValue[]>([])
const liveSamples = ref<TelemetrySample[]>([])

function acceptSamples(samples: TelemetrySample[]): void {
  liveSamples.value = mergeTelemetrySamples(liveSamples.value, samples, LIVE_SAMPLE_LIMIT)
  // Each metric follows event time, so a late sample never replaces a newer value.
  latest.value = samples.reduce(applySampleToLatestValues, latest.value)
}

async function recoverGap(tenantId: string): Promise<void> {
  const newest = latest.value.reduce<string | null>(
    (max, value) => max === null || value.occurred_at > max ? value.occurred_at : max,
    null
  )
  const since = newest ?? new Date(Date.now() - 60 * 60 * 1000).toISOString()
  const range = { from: since, to: new Date(Date.now() + FUTURE_SKEW_MS).toISOString(), metrics: [] }
  let cursor: string | null = null

  try {
    for (let page = 0; page < RECOVERY_MAX_PAGES; page++) {
      const result = await loadTelemetryHistory(deviceScope(tenantId), range, HISTORY_LIMIT, cursor)
      if (activeTenantId.value !== tenantId) {
        return
      }
      acceptSamples(result.samples)
      cursor = result.nextCursor

      if (cursor === null) {
        return
      }
    }
  } catch (error: unknown) {
    if (activeTenantId.value === tenantId) {
      notifyError(error, 'Telemetry could not be recovered')
    }
  }
}

const stream = useTelemetryStream(deviceScope, {
  onSample: sample => acceptSamples([sample]),
  onOutcome: outcome => acceptOutcomes([outcome]),
  onLive: async (tenantId) => {
    if (activeTenantId.value !== tenantId) {
      return
    }
    await Promise.all([recoverGap(tenantId), loadOutcomes(tenantId)])
  }
}, () => activeTenantId.value)

onMounted(async () => {
  const tenantId = activeTenantId.value
  if (tenantId !== null) {
    await loadLatestSnapshot(tenantId)
  }
  stream.start()
  presenceStream.start()
  if (tenantId !== null && activeTenantId.value === tenantId) {
    void loadOutcomes(tenantId)
  }
})

// Telemetry tab
const rangeOptions = [
  { label: 'Last hour', value: 60 },
  { label: 'Last 6 hours', value: 360 },
  { label: 'Last 24 hours', value: 1440 },
  { label: 'Last 7 days', value: 10080 }
]
const intervalOptions = [
  { label: 'Raw samples', value: 'raw' },
  { label: '1 minute', value: '1m' },
  { label: '5 minutes', value: '5m' },
  { label: '15 minutes', value: '15m' },
  { label: '1 hour', value: '1h' },
  { label: '1 day', value: '1d' }
]
const rangeMinutes = ref(60)
const interval = ref<'raw' | TelemetryInterval>('raw')
const metric = ref<string | undefined>(undefined)
const historySamples = ref<TelemetrySample[]>([])
const aggregates = ref<TelemetryAggregate[]>([])
const historyFrom = ref('')
const historyTruncated = ref(false)
const historyLoading = ref(false)

const metricOptions = computed(() => latest.value
  .filter(value => typeof value.value === 'number')
  .map(value => value.metric))

watch(metricOptions, (options) => {
  if (metric.value === undefined || !options.includes(metric.value)) {
    metric.value = options[0]
  }
}, { immediate: true })

async function loadHistory(): Promise<void> {
  const tenantId = activeTenantId.value
  if (tenantId === null) {
    return
  }

  historyLoading.value = true
  const now = Date.now()
  const range = {
    from: new Date(now - rangeMinutes.value * 60 * 1000).toISOString(),
    to: new Date(now + FUTURE_SKEW_MS).toISOString(),
    metrics: metric.value === undefined ? [] : [metric.value]
  }

  try {
    if (interval.value === 'raw') {
      const result = await loadTelemetryHistory(deviceScope(tenantId), range, HISTORY_LIMIT, null)
      if (activeTenantId.value !== tenantId) {
        return
      }
      historySamples.value = result.samples
      historyTruncated.value = result.nextCursor !== null
      aggregates.value = []
    } else {
      const result = await loadTelemetryAggregates(deviceScope(tenantId), range, interval.value)
      if (activeTenantId.value !== tenantId) {
        return
      }
      aggregates.value = result
      historySamples.value = []
      historyTruncated.value = false
    }
    historyFrom.value = range.from
  } catch (error: unknown) {
    if (activeTenantId.value === tenantId) {
      notifyError(error, 'Telemetry history could not be loaded')
    }
  } finally {
    if (activeTenantId.value === tenantId) {
      historyLoading.value = false
    }
  }
}

watch([rangeMinutes, interval, metric], () => {
  pointPagination.value.pageIndex = 0
})

watch([activeTab, rangeMinutes, interval, metric], () => {
  if (activeTab.value === 'telemetry') {
    void loadHistory()
  }
})

interface ChartPoint {
  t: number
  v: number
}

const chartPoints = computed<ChartPoint[]>(() => {
  const key = metric.value

  if (key === undefined) {
    return []
  }

  if (interval.value !== 'raw') {
    return aggregates.value
      .filter(row => row.metric === key)
      .map(row => ({ t: Date.parse(row.bucket_start), v: row.avg }))
  }

  // Raw view stays live: realtime samples inside the window are merged by sample id.
  const inWindow = liveSamples.value.filter(sample => sample.occurred_at >= historyFrom.value)

  return mergeTelemetrySamples(historySamples.value, inWindow, HISTORY_LIMIT)
    .flatMap((sample) => {
      const value = sample.values[key]

      return typeof value === 'number' ? [{ t: Date.parse(sample.occurred_at), v: value }] : []
    })
})

const pointRows = computed(() => chartPoints.value.toReversed())
const pointPagination = ref({ pageIndex: 0, pageSize: PAGE_SIZE })
const pointTable = useTemplateRef('pointTable')

const pointColumns = computed<TableColumn<ChartPoint>[]>(() => [
  {
    id: 'time',
    header: 'Time',
    cell: ({ row }) => h('span', { class: 'whitespace-nowrap text-muted' }, formatDateTime(new Date(row.original.t)))
  },
  {
    id: 'metric',
    header: 'Metric',
    cell: () => h('span', { class: 'font-mono text-xs' }, metric.value)
  },
  {
    id: 'value',
    accessorKey: 'v',
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: interval.value === 'raw' ? 'Value' : 'Average',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
    cell: ({ row }) => h('span', { class: 'font-mono' }, String(row.original.v)),
    meta: { class: { th: 'text-right', td: 'text-right' } }
  }
])

// Message Inspector tab
const outcomes = ref<TelemetryIngestOutcome[]>([])
const outcomesLoading = ref(false)
const messageFilter = ref('')
const appliedMessageFilter = ref('')
const ALL = 'all'
const statusFilter = ref(ALL)
const reasonFilter = ref(ALL)
const statusOptions = [
  { label: 'Status', value: ALL },
  ...Object.entries(telemetryOutcomeStatusPresentation).map(([value, { label }]) => ({ label, value }))
]
const reasonOptions = computed(() => [
  { label: 'Reasons', value: ALL },
  ...[...new Set(outcomes.value.flatMap(outcome => outcome.reason_codes.map(reason => reason.code)))]
    .sort()
    .map(code => ({ label: code, value: code }))
])
const outcomePagination = ref({ pageIndex: 0, pageSize: PAGE_SIZE })
const outcomeTable = useTemplateRef('outcomeTable')
const traceQuery = ref('')
const trace = ref<TelemetryTrace | null>(null)
const traceLoading = ref(false)
const traceDrawerOpen = ref(false)

const errorColors = ['#ef4444', '#f97316', '#eab308', '#8b5cf6', '#0ea5e9']
const persistedColor = '#22c55e'
const summary = computed(() => summarizeOutcomes(outcomes.value))
const recentColumns = computed(() => outcomeColumns.value
  .filter(column => column.id !== 'message_id')
  .map(column => column.id === 'status' ? { ...column, header: 'Status' } : column.id === 'reasons' ? { ...column, header: 'Reasons' } : column))
const donutSlices = computed(() => [
  ...summary.value.persistedCount > 0 ? [{ label: 'Persisted', count: summary.value.persistedCount, color: persistedColor }] : [],
  ...summary.value.topReasons.map((reason, index) => ({ label: reason.code, count: reason.count, color: errorColors[index] }))
])

watch(activeTenantId, () => {
  devicePresence.value = null
  deviceSnapshot.value = null
  latest.value = []
  liveSamples.value = []
  historySamples.value = []
  aggregates.value = []
  historyFrom.value = ''
  historyTruncated.value = false
  outcomes.value = []
  trace.value = null
  traceDrawerOpen.value = false
  messageFilter.value = ''
  appliedMessageFilter.value = ''
  statusFilter.value = ALL
  reasonFilter.value = ALL
  outcomePagination.value.pageIndex = 0
  outcomesLoading.value = false
  historyLoading.value = false
  traceLoading.value = false
  const tenantId = activeTenantId.value
  if (tenantId !== null) {
    void loadLatestSnapshot(tenantId)
    void loadOutcomes(tenantId)
    if (activeTab.value === 'telemetry') {
      void loadHistory()
    }
  }
}, { flush: 'sync' })

// shortcut: the realtime stream is per device, so a gateway polls its sub-devices' outcomes; add a gateway stream if 5 s is too slow.
const { pause: pauseGatewayPolling, resume: resumeGatewayPolling } = useIntervalFn(() => {
  if (device.value?.is_gateway === true && document.visibilityState === 'visible') {
    void loadOutcomes()
  }
}, 5000, { immediate: false })
onMounted(resumeGatewayPolling)
onBeforeUnmount(pauseGatewayPolling)

function acceptOutcomes(incoming: TelemetryIngestOutcome[]): void {
  outcomes.value = mergeTelemetryOutcomes(outcomes.value, incoming, OUTCOME_LIMIT)
}

const filteredOutcomes = computed(() => {
  const query = appliedMessageFilter.value.toLowerCase()

  return outcomes.value.filter(outcome =>
    (statusFilter.value === ALL || outcome.status === statusFilter.value)
    && (reasonFilter.value === ALL || outcome.reason_codes.some(reason => reason.code === reasonFilter.value))
    && (query === ''
      || outcome.processing_id.toLowerCase().includes(query)
      || (outcome.message_id !== null && outcome.message_id.toLowerCase().includes(query)))
  )
})

watch(filteredOutcomes, () => {
  outcomePagination.value.pageIndex = 0
})

const outcomeColumns = computed<TableColumn<TelemetryIngestOutcome>[]>(() => [
  {
    id: 'received',
    header: 'Received',
    cell: ({ row }) => h('span', { class: 'whitespace-nowrap text-muted' }, formatDateTime(row.original.received_at))
  },
  ...(device.value?.is_gateway
    ? [{
        id: 'device',
        header: 'Device',
        cell: ({ row }: { row: { original: TelemetryIngestOutcome } }) => {
          const subDevice = subDevices.value?.find(item => item.id === row.original.device_id)

          return subDevice === undefined
            ? h('span', { class: 'text-muted' }, row.original.device_id === deviceId ? 'Gateway' : '—')
            : h(NuxtLink, { to: `/devices/${projectId}/${subDevice.id}`, class: 'font-medium text-highlighted hover:underline' }, () => subDevice.name)
        }
      }]
    : []),
  {
    id: 'message_id',
    header: 'Message ID',
    cell: ({ row }) => {
      const id = row.original.message_id ?? row.original.processing_id

      return h('span', { class: 'block max-w-72 truncate font-mono text-xs', title: id }, id)
    }
  },
  {
    id: 'status',
    header: () => h(USelect, {
      'modelValue': statusFilter.value,
      'onUpdate:modelValue': (value: string) => { statusFilter.value = value },
      'items': statusOptions,
      'variant': 'ghost',
      'class': '-ml-2.5 w-44 font-medium',
      'aria-label': 'Filter by status'
    }),
    cell: ({ row }) => h(TelemetryOutcomeStatus, { status: row.original.status })
  },
  {
    id: 'reasons',
    header: () => h(USelect, {
      'modelValue': reasonFilter.value,
      'onUpdate:modelValue': (value: string) => { reasonFilter.value = value },
      'items': reasonOptions.value,
      'variant': 'ghost',
      'class': '-ml-2.5 w-48 font-medium',
      'aria-label': 'Filter by reason'
    }),
    cell: ({ row }) => {
      const reason = row.original.reason_codes[0]

      return reason
        ? h(UBadge, { color: row.original.status === 'skipped' ? 'neutral' : 'error', variant: 'subtle', class: 'font-mono' }, () => reason.code)
        : h('span', { class: 'text-muted' }, '—')
    }
  },
  {
    id: 'details',
    header: 'Details',
    cell: ({ row }) => h(UButton, {
      label: 'View trace',
      icon: 'i-lucide-arrow-up-right',
      color: 'neutral',
      variant: 'ghost',
      size: 'sm',
      onClick: () => openTrace(row.original.processing_id)
    }),
    meta: { class: { th: 'text-right', td: 'text-right' } }
  }
])

async function loadOutcomes(tenantId = activeTenantId.value): Promise<void> {
  if (tenantId === null) {
    return
  }

  outcomesLoading.value = true

  try {
    const incoming = await loadMessageOutcomes(deviceScope(tenantId), OUTCOME_LIMIT)
    if (activeTenantId.value === tenantId) {
      acceptOutcomes(incoming)
    }
  } catch (error: unknown) {
    if (activeTenantId.value === tenantId) {
      notifyError(error, 'Message outcomes could not be loaded')
    }
  } finally {
    if (activeTenantId.value === tenantId) {
      outcomesLoading.value = false
    }
  }
}

async function openTrace(message: string): Promise<void> {
  const tenantId = activeTenantId.value
  if (tenantId === null) {
    return
  }

  traceLoading.value = true
  traceQuery.value = message
  trace.value = null
  traceDrawerOpen.value = true

  try {
    const loadedTrace = await loadMessageTrace(deviceScope(tenantId), message)
    if (activeTenantId.value === tenantId) {
      trace.value = loadedTrace
    }
  } catch (error: unknown) {
    if (activeTenantId.value === tenantId) {
      trace.value = null
      notifyError(error, 'Message trace could not be loaded')
    }
  } finally {
    if (activeTenantId.value === tenantId) {
      traceLoading.value = false
    }
  }
}
</script>

<template>
  <UDashboardPanel id="device-detail" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar :title="device?.name ?? 'Device'">
        <template #leading>
          <UButton
            icon="i-lucide-arrow-left"
            color="neutral"
            variant="ghost"
            to="/devices"
            aria-label="Back to devices"
          />
        </template>

        <template #right>
          <DevicePresenceStatus
            v-if="device"
            :state="device.connection_state"
            :last-seen-at="device.last_seen_at"
            compact
          />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <UNavigationMenu
          :items="tabs"
          highlight
          class="-mx-1 flex-1"
        />
      </UDashboardToolbar>
    </template>

    <template #body>
      <UAlert
        v-if="deviceError"
        color="error"
        variant="subtle"
        title="The device could not be loaded."
        :description="deviceError.message"
      />

      <UAlert
        v-if="device?.gateway_device_id"
        color="info"
        variant="subtle"
        icon="i-lucide-router"
        title="Connected through a gateway"
        :actions="[{ label: 'Open gateway', color: 'neutral', variant: 'outline', to: `/devices/${projectId}/${device.gateway_device_id}` }]"
        class="mb-4 shrink-0"
      />

      <template v-if="!deviceError">
        <div v-if="activeTab === 'sub-devices'">
          <div class="pt-4">
            <p v-if="!subDevices?.length" class="py-10 text-center text-sm text-muted">
              No sub-devices yet. They are created when this gateway first publishes for a new code.
            </p>
            <ul v-else class="divide-y divide-default rounded-md border border-default">
              <li v-for="subDevice in subDevices" :key="subDevice.id">
                <NuxtLink
                  :to="`/devices/${projectId}/${subDevice.id}`"
                  class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-elevated"
                >
                  <span class="min-w-0">
                    <span class="block truncate font-medium text-highlighted">{{ subDevice.name }}</span>
                    <span class="block truncate font-mono text-xs text-muted">{{ subDevice.code }}</span>
                  </span>
                  <DevicePresenceStatus :state="subDevice.connection_state" :last-seen-at="subDevice.last_seen_at" compact />
                </NuxtLink>
              </li>
            </ul>
          </div>
        </div>

        <div v-if="activeTab === 'overview'">
          <div class="space-y-4 pt-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <UCard>
                <p class="text-xs text-muted">
                  Last active
                </p>
                <p class="text-lg font-semibold">
                  {{ device?.last_seen_at ? formatDateTime(device.last_seen_at) : 'Never' }}
                </p>
              </UCard>
              <UCard>
                <p class="text-xs text-muted">
                  Avg. interval
                </p>
                <p class="text-2xl font-semibold">
                  {{ summary.averageGapSeconds === null ? '—' : `${summary.averageGapSeconds.toFixed(1)} s` }}
                </p>
              </UCard>
              <UCard>
                <p class="text-xs text-muted">
                  Success rate
                </p>
                <p class="text-2xl font-semibold">
                  {{ summary.successRate === null ? '—' : `${summary.successRate}%` }}
                </p>
              </UCard>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
              <div class="space-y-4">
                <UCard>
                  <p v-if="donutSlices.length === 0" class="text-sm text-muted">
                    No processed messages yet.
                  </p>
                  <div v-else class="flex flex-wrap items-center gap-6">
                    <ClientOnly>
                      <VisSingleContainer :data="donutSlices" :height="160" :width="160">
                        <VisDonut
                          :value="(slice: { count: number }) => slice.count"
                          :color="(slice: { color: string }) => slice.color"
                          :arc-width="28"
                        />
                      </VisSingleContainer>
                    </ClientOnly>
                    <ul class="min-w-0 flex-1 space-y-2 text-sm">
                      <li v-for="slice in donutSlices" :key="slice.label" class="flex items-center justify-between gap-2">
                        <span class="flex min-w-0 items-center gap-2">
                          <span class="size-2 shrink-0 rounded-full" :style="{ backgroundColor: slice.color }" />
                          <span class="truncate" :class="{ 'font-mono': slice.color !== persistedColor }">{{ slice.label }}</span>
                        </span>
                        <span class="text-muted">{{ slice.count }}×</span>
                      </li>
                    </ul>
                  </div>
                </UCard>

                <UCard>
                  <p class="mb-2 text-xs text-muted">
                    Delay between consecutive messages, in seconds.
                  </p>
                  <p v-if="summary.recentGaps.length === 0" class="text-sm text-muted">
                    Not enough messages yet.
                  </p>
                  <ClientOnly v-else>
                    <VisXYContainer :data="summary.recentGaps" :height="140">
                      <VisLine :x="(gap: { index: number }) => gap.index" :y="(gap: { seconds: number }) => gap.seconds" />
                      <VisAxis type="y" label="seconds" />
                    </VisXYContainer>
                  </ClientOnly>
                </UCard>
              </div>

              <div class="relative h-96 lg:col-span-2 lg:h-auto">
                <UCard
                  class="flex h-full flex-col lg:absolute lg:inset-0"
                  :ui="{ root: 'flex flex-col', body: 'min-h-0 flex-1 overflow-hidden p-0 sm:p-0' }"
                >
                  <UTable
                    sticky
                    class="h-full"
                    :data="outcomes"
                    :columns="recentColumns"
                    :loading="outcomesLoading"
                    :ui="{ ...tableUi, thead: `${tableUi.thead} bg-default` }"
                  >
                    <template #empty>
                      <p class="py-10 text-center text-muted">
                        No processed messages yet.
                      </p>
                    </template>
                  </UTable>
                </UCard>
              </div>
            </div>
          </div>
        </div>

        <div v-if="activeTab === 'telemetry'">
          <div class="space-y-4 pt-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p class="font-medium">
                  Telemetry history
                </p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <USelect
                  v-model="rangeMinutes"
                  :items="rangeOptions"
                  class="w-44"
                  aria-label="Time range"
                />
                <USelect
                  v-model="interval"
                  :items="intervalOptions"
                  class="w-44"
                  aria-label="Aggregation interval"
                />
                <USelect
                  v-model="metric"
                  :items="metricOptions"
                  class="w-44"
                  placeholder="Metric"
                  aria-label="Metric"
                />
                <UButton
                  icon="i-lucide-refresh-cw"
                  color="neutral"
                  variant="ghost"
                  :loading="historyLoading"
                  aria-label="Refresh telemetry"
                  @click="loadHistory"
                />
              </div>
            </div>

            <UAlert
              v-if="historyTruncated"
              color="warning"
              variant="subtle"
              :title="`Showing the first ${HISTORY_LIMIT} samples. Choose an aggregation interval to see the whole range.`"
            />

            <UCard v-if="chartPoints.length > 0">
              <ClientOnly>
                <VisXYContainer :data="chartPoints" :height="240">
                  <VisLine :x="(point: ChartPoint) => point.t" :y="(point: ChartPoint) => point.v" />
                  <VisAxis type="x" :tick-format="(t: number) => formatDateTime(new Date(t))" :num-ticks="5" />
                  <VisAxis type="y" :label="interval === 'raw' ? metric : `${metric} (avg)`" />
                </VisXYContainer>
              </ClientOnly>
            </UCard>

            <UTable
              ref="pointTable"
              v-model:pagination="pointPagination"
              :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
              class="shrink-0"
              :data="pointRows"
              :columns="pointColumns"
              :loading="historyLoading"
              :ui="tableUi"
            >
              <template #empty>
                <p class="py-10 text-center text-muted">
                  No numeric samples in this range.
                </p>
              </template>
            </UTable>

            <div class="flex items-center justify-between gap-3 border-t border-default pt-4">
              <div class="text-sm text-muted">
                {{ pointRows.length }} row(s).
              </div>

              <UPagination
                :page="pointPagination.pageIndex + 1"
                :items-per-page="pointPagination.pageSize"
                :total="pointRows.length"
                @update:page="(page: number) => pointTable?.tableApi?.setPageIndex(page - 1)"
              />
            </div>
          </div>
        </div>

        <div v-if="activeTab === 'alerts'">
          <div class="flex flex-col gap-4 pt-4">
            <AdminTable
              :columns="alertColumns"
              :data="alertRows"
              :loading="alertsStatus === 'pending'"
              :show-status-filter="false"
              search-placeholder="Search alerts..."
            />
          </div>
        </div>

        <div v-if="activeTab === 'settings'">
          <AdminDeviceEditForm
            v-if="editContext && device"
            :device="device"
            :project-name="editContext.project.name"
            :equipment-asset-options="equipmentAssetOptions"
            :device-profiles="editContext.deviceProfiles"
            :loading="editLoading"
            @submit="saveDevice"
          />
        </div>

        <div v-if="activeTab === 'inspector'">
          <div class="space-y-4 pt-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p class="font-medium">
                  Recent messages
                </p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <form class="flex min-w-64 gap-2" @submit.prevent="appliedMessageFilter = messageFilter">
                  <UInput
                    v-model="messageFilter"
                    placeholder="message_id or processing ID"
                    class="flex-1"
                    aria-label="Message ID or processing ID"
                  />
                  <UButton
                    type="submit"
                    label="Search"
                    :disabled="messageFilter === appliedMessageFilter"
                  />
                </form>
                <UButton
                  icon="i-lucide-refresh-cw"
                  color="neutral"
                  variant="ghost"
                  :loading="outcomesLoading"
                  aria-label="Refresh messages"
                  @click="() => loadOutcomes()"
                />
              </div>
            </div>

            <UTable
              ref="outcomeTable"
              v-model:pagination="outcomePagination"
              :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
              class="shrink-0"
              :data="filteredOutcomes"
              :columns="outcomeColumns"
              :loading="outcomesLoading"
              :ui="tableUi"
            >
              <template #empty>
                <p class="py-10 text-center text-muted">
                  {{ appliedMessageFilter === '' && statusFilter === ALL && reasonFilter === ALL ? 'No processed messages yet.' : 'No messages match this search.' }}
                </p>
              </template>
            </UTable>

            <div class="flex items-center justify-between gap-3 border-t border-default pt-4">
              <div class="text-sm text-muted">
                {{ filteredOutcomes.length }} row(s).
              </div>

              <UPagination
                :page="outcomePagination.pageIndex + 1"
                :items-per-page="outcomePagination.pageSize"
                :total="filteredOutcomes.length"
                @update:page="(page: number) => outcomeTable?.tableApi?.setPageIndex(page - 1)"
              />
            </div>
          </div>
        </div>
      </template>

      <AlertingAlertDetailModal
        v-model:open="alertViewOpen"
        :alert="viewingAlert"
        :device-name="device?.name ?? deviceId"
        :rule-name="viewingAlert === null ? 'Alert' : alertRuleNames.get(viewingAlert.alert_rule_id) ?? 'Alert'"
        :project-name="editContext?.project.name"
        :can-acknowledge="editContext !== null && editContext !== undefined"
        :loading="alertViewLoading"
        @acknowledge="viewingAlert !== null && acknowledge(viewingAlert)"
      />

      <UDrawer
        v-model:open="traceDrawerOpen"
        direction="right"
        :handle="false"
        handle-only
        :dismissible="false"
        close
        :description="traceQuery"
        :ui="{
          content: 'w-full sm:max-w-3xl select-text',
          container: 'flex h-full flex-col select-text',
          body: 'flex-1 min-h-0 overflow-y-auto p-4 sm:p-6 select-text',
          footer: 'flex w-full items-center justify-start border-t border-default p-4 sm:p-6'
        }"
      >
        <template #title>
          <div class="flex items-center gap-2.5">
            <UIcon name="i-lucide-radio-tower" class="size-5 shrink-0 text-primary" />
            <div class="flex min-w-0 items-center gap-2">
              <span class="truncate font-semibold">Message trace</span>
              <TelemetryOutcomeStatus
                v-if="trace?.outcomes[0]"
                :status="trace.outcomes[0].status"
              />
            </div>
          </div>
        </template>

        <template #body>
          <div v-if="traceLoading" class="flex min-h-48 items-center justify-center">
            <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-primary" />
            <span class="sr-only">Loading message trace</span>
          </div>
          <UEmpty
            v-else-if="trace === null || trace.outcomes.length === 0"
            icon="i-lucide-message-circle-warning"
            title="No trace found"
            description="No processing outcomes were returned for this message ID."
            variant="naked"
          />
          <div v-else class="space-y-6">
            <section
              v-for="outcome in trace.outcomes"
              :key="outcome.id"
              class="space-y-4"
            >
              <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-xs text-muted">
                    Processing ID
                  </p>
                  <p class="break-all font-mono text-sm">
                    {{ outcome.processing_id }}
                  </p>
                </div>
                <TelemetryOutcomeStatus :status="outcome.status" />
              </div>

              <section>
                <h3 class="mb-2 text-sm font-medium">
                  Validation reasons
                </h3>
                <p v-if="!hasValidationErrors(outcome)" class="text-sm text-muted">
                  No validation errors.
                </p>
                <div v-else class="overflow-x-auto rounded-lg border border-default">
                  <table class="w-full text-sm">
                    <thead class="border-b border-default bg-elevated/50 text-left text-xs text-muted">
                      <tr>
                        <th scope="col" class="px-3 py-2 font-medium">
                          Code
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">
                          Field
                        </th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-default">
                      <tr v-for="reason in outcome.reason_codes" :key="`${reason.code}:${reason.field}`">
                        <td class="px-3 py-2 font-mono text-error">
                          {{ reason.code }}
                        </td>
                        <td class="px-3 py-2 font-mono">
                          {{ reason.field ?? '—' }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </section>

              <section>
                <h3 class="mb-2 text-sm font-medium">
                  Processing stages
                </h3>
                <p v-if="outcome.stages.length === 0" class="text-sm text-muted">
                  No processing stages recorded.
                </p>
                <div v-else class="overflow-x-auto rounded-lg border border-default">
                  <table class="w-full text-sm">
                    <thead class="border-b border-default bg-elevated/50 text-left text-xs text-muted">
                      <tr>
                        <th scope="col" class="px-3 py-2 font-medium">
                          Stage
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">
                          Elapsed
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">
                          Time
                        </th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-default">
                      <tr v-for="stage in outcome.stages" :key="`${outcome.id}:${stage.stage}`">
                        <td class="px-3 py-2 font-medium">
                          {{ stage.stage === 'schema_validation_skipped' ? 'schema validation skipped' : stage.stage }}
                          <span v-if="stage.subscribers !== undefined" class="font-normal text-muted">
                            · {{ stage.subscribers }} subscribers
                          </span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-muted">
                          <template v-if="outcome.stages[0]">
                            +{{ (Date.parse(stage.at) - Date.parse(outcome.stages[0].at)).toFixed(0) }} ms
                          </template>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-muted">
                          {{ formatDateTime(stage.at) }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </section>
            </section>

            <section v-if="trace.sample" class="space-y-2">
              <h3 class="text-sm font-medium">
                Stored sample
              </h3>
              <p class="text-xs text-muted">
                Occurred {{ formatDateTime(trace.sample.occurred_at) }}
              </p>
              <pre class="overflow-x-auto rounded-lg border border-default bg-elevated/30 p-3 text-xs">{{ JSON.stringify(trace.sample.values, null, 2) }}</pre>
            </section>
          </div>
        </template>

        <template #footer>
          <div class="flex w-full items-center justify-start">
            <UButton
              label="Close"
              color="neutral"
              variant="subtle"
              @click="traceDrawerOpen = false"
            />
          </div>
        </template>
      </UDrawer>
    </template>
  </UDashboardPanel>
</template>
