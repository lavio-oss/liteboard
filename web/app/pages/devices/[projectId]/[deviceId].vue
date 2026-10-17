<script setup lang="ts">
import { VisAxis, VisLine, VisXYContainer } from '@unovis/vue'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useTelemetry } from '~/composables/useTelemetry'
import { useDevicePresenceStream } from '~/composables/useDevicePresenceStream'
import type { TelemetryDeviceScope } from '~/composables/useTelemetry'
import { useTelemetryStream } from '~/composables/useTelemetryStream'
import { useTenantContext } from '~/composables/useTenantContext'
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
import { hasValidationErrors, mergeTelemetryOutcomes } from '~/utils/telemetryOutcomes'
import { applySampleToLatestValues, mergeTelemetrySamples } from '~/utils/telemetrySamples'
import { applyDevicePresenceToDevice, mergeDevicePresenceEvents } from '~/utils/devicePresence'

definePageMeta({
  middleware: 'auth'
})

const LIVE_SAMPLE_LIMIT = 500
const HISTORY_LIMIT = 1000
const RECOVERY_MAX_PAGES = 5
const MESSAGE_PAGE_SIZE = 10
const OUTCOME_LIMIT = 50
// Devices may report up to five minutes ahead of the server (IT-3 clock-skew policy).
const FUTURE_SKEW_MS = 5 * 60 * 1000

const route = useRoute()
const toast = useToast()
const { activeTenantId } = useTenantContext()
const { loadDevice } = useAdmin()
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

const tabs = [
  { label: 'Overview', value: 'overview', slot: 'overview' },
  { label: 'Telemetry', value: 'telemetry', slot: 'telemetry' },
  { label: 'Message Inspector', value: 'inspector', slot: 'inspector' }
]
const activeTab = ref('overview')

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

// Message Inspector tab
const outcomes = ref<TelemetryIngestOutcome[]>([])
const outcomesLoading = ref(false)
const messageFilter = ref('')
const appliedMessageFilter = ref('')
const currentMessagePage = ref(1)
const traceQuery = ref('')
const trace = ref<TelemetryTrace | null>(null)
const traceLoading = ref(false)
const traceDrawerOpen = ref(false)

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
  currentMessagePage.value = 1
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

function acceptOutcomes(incoming: TelemetryIngestOutcome[]): void {
  outcomes.value = mergeTelemetryOutcomes(outcomes.value, incoming, OUTCOME_LIMIT)
}

const filteredOutcomes = computed(() => {
  const query = appliedMessageFilter.value.toLowerCase()

  if (query === '') {
    return outcomes.value
  }

  return outcomes.value.filter(outcome =>
    outcome.processing_id.toLowerCase().includes(query)
    || (outcome.message_id !== null && outcome.message_id.toLowerCase().includes(query))
  )
})

const paginatedOutcomes = computed(() => {
  const start = (currentMessagePage.value - 1) * MESSAGE_PAGE_SIZE

  return filteredOutcomes.value.slice(start, start + MESSAGE_PAGE_SIZE)
})

watch(filteredOutcomes, () => {
  currentMessagePage.value = 1
})

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
    </template>

    <template #body>
      <UAlert
        v-if="deviceError"
        color="error"
        variant="subtle"
        title="The device could not be loaded."
        :description="deviceError.message"
      />

      <UTabs
        v-else
        v-model="activeTab"
        :items="tabs"
        variant="link"
        class="w-full"
      >
        <template #overview>
          <div class="grid gap-4 pt-4 lg:grid-cols-3">
            <UCard>
              <template #header>
                <p class="font-medium">
                  Device
                </p>
              </template>
              <dl v-if="device" class="space-y-2 text-sm">
                <div class="flex justify-between gap-2">
                  <dt class="text-muted">
                    Code
                  </dt>
                  <dd>{{ device.code }}</dd>
                </div>
                <div class="flex items-start justify-between gap-2">
                  <dt class="text-muted">
                    Device connection
                  </dt>
                  <dd>
                    <DevicePresenceStatus
                      :state="device.connection_state"
                      :last-seen-at="device.last_seen_at"
                    />
                  </dd>
                </div>
              </dl>
            </UCard>

            <UCard class="lg:col-span-2">
              <template #header>
                <p class="font-medium">
                  Latest telemetry
                </p>
              </template>

              <p v-if="latest.length === 0" class="text-sm text-muted">
                No telemetry has been stored for this device yet.
              </p>
              <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div
                  v-for="latestValue in latest"
                  :key="latestValue.metric"
                  class="rounded-md border border-default p-3"
                >
                  <p class="text-xs text-muted">
                    {{ latestValue.metric }}
                  </p>
                  <p class="text-lg font-semibold">
                    {{ latestValue.value }}
                  </p>
                  <p class="text-xs text-muted">
                    {{ formatDateTime(latestValue.occurred_at) }}
                  </p>
                </div>
              </div>
            </UCard>
          </div>
        </template>

        <template #telemetry>
          <div class="space-y-4 pt-4">
            <div class="flex flex-wrap gap-2">
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
            </div>

            <UAlert
              v-if="historyTruncated"
              color="warning"
              variant="subtle"
              :title="`Showing the first ${HISTORY_LIMIT} samples. Choose an aggregation interval to see the whole range.`"
            />

            <UCard>
              <p v-if="chartPoints.length === 0" class="py-12 text-center text-sm text-muted">
                {{ historyLoading
                  ? 'Loading telemetry…'
                  : 'No numeric samples in this range.' }}
              </p>
              <ClientOnly v-else>
                <VisXYContainer :data="chartPoints" :height="320">
                  <VisLine :x="(point: ChartPoint) => point.t" :y="(point: ChartPoint) => point.v" />
                  <VisAxis type="x" :tick-format="(t: number) => formatDateTime(new Date(t))" :num-ticks="5" />
                  <VisAxis type="y" :label="interval === 'raw' ? metric : `${metric} (avg)`" />
                </VisXYContainer>
              </ClientOnly>
            </UCard>
          </div>
        </template>

        <template #inspector>
          <div class="space-y-4 pt-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p class="font-medium">
                  Recent messages
                </p>
                <p class="text-sm text-muted">
                  Select a row to inspect validation reasons and processing stages.
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

            <div class="overflow-x-auto">
              <table class="w-full min-w-[52rem] border-separate border-spacing-0 text-sm">
                <thead class="[&>tr]:bg-elevated/50 [&>tr]:after:content-none">
                  <tr>
                    <th scope="col" class="border-y border-default px-3 py-2 text-left font-medium first:rounded-l-lg first:border-l last:rounded-r-lg last:border-r">
                      Received
                    </th>
                    <th scope="col" class="border-y border-default px-3 py-2 text-left font-medium first:rounded-l-lg first:border-l last:rounded-r-lg last:border-r">
                      Message ID
                    </th>
                    <th scope="col" class="border-y border-default px-3 py-2 text-left font-medium first:rounded-l-lg first:border-l last:rounded-r-lg last:border-r">
                      Status
                    </th>
                    <th scope="col" class="border-y border-default px-3 py-2 text-left font-medium first:rounded-l-lg first:border-l last:rounded-r-lg last:border-r">
                      Reasons
                    </th>
                    <th scope="col" class="border-y border-default px-3 py-2 text-right font-medium first:rounded-l-lg first:border-l last:rounded-r-lg last:border-r">
                      Details
                    </th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-default [&>tr:last-child>td]:border-b-0">
                  <tr v-for="outcome in paginatedOutcomes" :key="outcome.id" class="hover:bg-elevated/50">
                    <td class="border-b border-default whitespace-nowrap px-3 py-3 text-muted">
                      {{ formatDateTime(outcome.received_at) }}
                    </td>
                    <td class="max-w-72 truncate border-b border-default px-3 py-3 font-mono text-xs" :title="outcome.message_id ?? outcome.processing_id">
                      {{ outcome.message_id ?? outcome.processing_id }}
                    </td>
                    <td class="border-b border-default px-3 py-3">
                      <TelemetryOutcomeStatus :status="outcome.status" />
                    </td>
                    <td class="border-b border-default px-3 py-3">
                      <UBadge
                        v-if="outcome.reason_codes[0]"
                        :color="outcome.status === 'skipped' ? 'neutral' : 'error'"
                        variant="subtle"
                        class="font-mono"
                      >
                        {{ outcome.reason_codes[0].code }}
                      </UBadge>
                      <span v-else class="text-muted">—</span>
                    </td>
                    <td class="border-b border-default px-3 py-3 text-right">
                      <UButton
                        label="View trace"
                        icon="i-lucide-arrow-up-right"
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        @click="openTrace(outcome.processing_id)"
                      />
                    </td>
                  </tr>
                  <tr v-if="filteredOutcomes.length === 0">
                    <td colspan="5" class="border-b border-default px-3 py-10 text-center text-muted">
                      {{ outcomesLoading ? 'Loading messages…' : appliedMessageFilter === '' ? 'No processed messages yet.' : 'No messages match this search.' }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-default pt-4">
              <p class="text-sm text-muted">
                {{ filteredOutcomes.length }} message(s)
              </p>
              <UPagination
                v-model:page="currentMessagePage"
                :items-per-page="MESSAGE_PAGE_SIZE"
                :total="filteredOutcomes.length"
              />
            </div>
          </div>

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
              footer: 'flex w-full items-center justify-end border-t border-default p-4 sm:p-6'
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
              <div class="flex w-full items-center justify-end">
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
      </UTabs>
    </template>
  </UDashboardPanel>
</template>
