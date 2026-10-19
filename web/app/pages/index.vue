<script setup lang="ts">
import { ApiError } from '~/composables/useApi'
import { useAlerting } from '~/composables/useAlerting'
import { useAlertingProject } from '~/composables/useAlertingProject'
import { useOverview } from '~/composables/useOverview'

definePageMeta({
  middleware: 'auth'
})

const REFRESH_MS = 30_000
const REASON_LABELS: Record<string, string> = {
  schema_version_mismatch: 'Schema version mismatch',
  unknown_field: 'Unknown field',
  missing_required_field: 'Missing required field',
  invalid_type: 'Invalid type',
  invalid_payload: 'Invalid payload',
  out_of_range: 'Out of range'
}
const ATTENTION_LABELS = {
  pending_approval: { label: 'Pending approval', color: 'warning' },
  not_online: { label: 'Not online', color: 'neutral' },
  unassigned: { label: 'No profile version', color: 'info' }
} as const

const toast = useToast()
const alerting = useAlerting()
const { loadProjectOverview } = useOverview()
const {
  canWriteInSelectedProject,
  notifyError,
  projectSelectOptions,
  projects,
  projectsError,
  projectsStatus,
  requireProject,
  selectedProject,
  selectedProjectId
} = await useAlertingProject()

const { data: overview, status, error, refresh } = await useAsyncData(
  'liteboard-project-overview',
  async () => {
    const project = selectedProject.value

    return project === null ? null : loadProjectOverview(project.tenant_id, project.id)
  },
  { watch: [selectedProjectId] }
)

// Keeps the numbers live without a manual reload; paused while the tab is hidden.
const { pause, resume } = useIntervalFn(() => {
  if (document.visibilityState === 'visible') {
    refresh()
  }
}, REFRESH_MS, { immediate: false })
onMounted(resume)
onBeforeUnmount(pause)

const ingestRate = computed(() => {
  if (!overview.value) {
    return null
  }

  const { persisted, rejected } = overview.value.ingest.totals

  return persisted + rejected === 0 ? null : Math.round((persisted / (persisted + rejected)) * 1000) / 10
})
const deliveryFailures = computed(() => {
  const pipeline = overview.value?.pipeline

  return pipeline === null || pipeline === undefined ? null : pipeline.deliveries.failed + pipeline.deliveries.exhausted
})
const reasonPeak = computed(() => Math.max(1, ...(overview.value?.ingest.top_reasons.map(reason => reason.count) ?? [])))

const acknowledgingId = ref<string | null>(null)

async function acknowledge(alertId: string): Promise<void> {
  const project = requireProject()
  acknowledgingId.value = alertId

  try {
    await alerting.acknowledgeAlert(project.tenant_id, project.id, alertId)
    await refresh()
    toast.add({ title: 'Alert acknowledged', color: 'success' })
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to acknowledge alert')
  } finally {
    acknowledgingId.value = null
  }
}

const errorMessage = computed(() => {
  if (projectsError.value instanceof ApiError) {
    return projectsError.value.message
  }

  if (error.value instanceof ApiError) {
    return error.value.message
  }

  return 'The overview could not be loaded.'
})
</script>

<template>
  <UDashboardPanel id="overview" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Overview">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <span v-if="overview" class="hidden text-xs text-muted sm:inline">
            Updated {{ formatDateTime(overview.generated_at).slice(11, 19) }}
          </span>
          <USelect
            v-if="projects.length > 1"
            v-model="selectedProjectId"
            :items="projectSelectOptions"
            class="min-w-44"
          />
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            aria-label="Refresh overview"
            :loading="status === 'pending'"
            @click="refresh()"
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
          title="Unable to load the overview"
          :description="errorMessage"
        />

        <UAlert
          v-if="projectsStatus !== 'pending' && projects.length === 0"
          color="warning"
          variant="subtle"
          title="No project assigned"
          description="Your account is not currently assigned to any project. Contact an administrator to request access."
        />

        <template v-if="overview">
          <!-- Headline numbers for the last 24 hours. -->
          <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-lg border border-default bg-elevated/30 p-4">
              <p class="text-xs font-medium text-muted">
                Devices online
              </p>
              <p class="mt-1 text-2xl font-semibold text-highlighted tabular-nums">
                {{ overview.devices.online }}<span class="text-base font-normal text-muted"> / {{ overview.devices.total }}</span>
              </p>
              <p class="mt-1 text-xs text-muted">
                {{ overview.devices.pending_approval }} pending · {{ overview.devices.unassigned }} without profile
              </p>
            </div>
            <div class="rounded-lg border border-default bg-elevated/30 p-4">
              <p class="text-xs font-medium text-muted">
                Active alerts
              </p>
              <p class="mt-1 text-2xl font-semibold text-highlighted tabular-nums">
                {{ overview.alerts.active }}
              </p>
              <p class="mt-1 text-xs text-muted">
                {{ overview.alerts.active_unacknowledged }} not acknowledged
              </p>
            </div>
            <div class="rounded-lg border border-default bg-elevated/30 p-4">
              <p class="text-xs font-medium text-muted">
                Alerts opened ({{ overview.window_hours }}h)
              </p>
              <p class="mt-1 text-2xl font-semibold text-highlighted tabular-nums">
                {{ overview.alerts.opened_in_window }}
              </p>
              <div class="mt-1 flex flex-wrap gap-1">
                <AlertingSeverityBadge
                  v-for="(count, severity) in overview.alerts.active_by_severity"
                  v-show="count > 0"
                  :key="severity"
                  :severity="severity"
                />
              </div>
            </div>
            <div class="rounded-lg border border-default bg-elevated/30 p-4">
              <p class="text-xs font-medium text-muted">
                Telemetry accepted ({{ overview.window_hours }}h)
              </p>
              <p class="mt-1 text-2xl font-semibold text-highlighted tabular-nums">
                {{ ingestRate === null ? '—' : `${ingestRate}%` }}
              </p>
              <p class="mt-1 text-xs text-muted">
                {{ overview.ingest.totals.persisted }} persisted · {{ overview.ingest.totals.rejected }} rejected
              </p>
            </div>
            <div class="rounded-lg border border-default bg-elevated/30 p-4">
              <p class="text-xs font-medium text-muted">
                Webhook failures ({{ overview.window_hours }}h)
              </p>
              <p class="mt-1 text-2xl font-semibold text-highlighted tabular-nums">
                {{ deliveryFailures === null ? '—' : deliveryFailures }}
              </p>
              <p class="mt-1 text-xs text-muted">
                {{ deliveryFailures === null ? 'Visible to admins and engineers' : `${overview.pipeline?.deliveries.succeeded} delivered` }}
              </p>
            </div>
          </div>

          <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-lg border border-default p-4">
              <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-highlighted">
                  Alerts needing action
                </h2>
                <UButton
                  label="All alerts"
                  to="/alerts"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  trailing-icon="i-lucide-arrow-right"
                />
              </div>
              <p v-if="overview.alerts.unacknowledged.length === 0" class="py-6 text-center text-sm text-muted">
                No unacknowledged alert.
              </p>
              <ul v-else class="divide-y divide-default">
                <li
                  v-for="alert in overview.alerts.unacknowledged"
                  :key="alert.id"
                  class="flex items-center gap-3 py-2"
                >
                  <AlertingSeverityBadge :severity="alert.severity" />
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-highlighted">
                      {{ alert.rule_name }}
                    </p>
                    <p class="truncate text-xs text-muted">
                      {{ alert.device_name }} · opened {{ formatDateTime(alert.opened_at) }}
                    </p>
                  </div>
                  <UButton
                    v-if="canWriteInSelectedProject"
                    label="Ack"
                    color="neutral"
                    variant="outline"
                    size="xs"
                    :loading="acknowledgingId === alert.id"
                    @click="acknowledge(alert.id)"
                  />
                </li>
              </ul>
            </section>

            <section class="rounded-lg border border-default p-4">
              <h2 class="mb-3 text-sm font-semibold text-highlighted">
                Telemetry ingest, last {{ overview.window_hours }}h
              </h2>
              <OverviewIngestChart :buckets="overview.ingest.buckets" />
            </section>
          </div>

          <div class="grid gap-4 xl:grid-cols-3">
            <section class="rounded-lg border border-default p-4">
              <h2 class="mb-3 text-sm font-semibold text-highlighted">
                Top rejection reasons
              </h2>
              <p v-if="overview.ingest.top_reasons.length === 0" class="py-6 text-center text-sm text-muted">
                No rejected telemetry.
              </p>
              <ul v-else class="space-y-2.5">
                <li v-for="reason in overview.ingest.top_reasons" :key="reason.code">
                  <div class="mb-1 flex justify-between gap-2 text-sm">
                    <span class="truncate text-highlighted">{{ REASON_LABELS[reason.code] ?? reason.code }}</span>
                    <span class="tabular-nums text-muted">{{ reason.count }}</span>
                  </div>
                  <div class="h-1.5 rounded-full bg-elevated">
                    <div class="h-1.5 rounded-full bg-[#d03b3b]" :style="{ width: `${(reason.count / reasonPeak) * 100}%` }" />
                  </div>
                </li>
              </ul>
            </section>

            <section class="rounded-lg border border-default p-4">
              <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-highlighted">
                  Devices needing attention
                </h2>
                <UButton
                  label="Devices"
                  to="/devices"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  trailing-icon="i-lucide-arrow-right"
                />
              </div>
              <p v-if="overview.devices.attention.length === 0" class="py-6 text-center text-sm text-muted">
                All devices are online and assigned.
              </p>
              <ul v-else class="max-h-72 divide-y divide-default overflow-y-auto pe-1">
                <li v-for="device in overview.devices.attention" :key="device.id" class="flex items-center gap-2 py-2">
                  <div class="min-w-0 flex-1">
                    <NuxtLink :to="`/devices/${selectedProjectId}/${device.id}`" class="block truncate text-sm font-medium text-highlighted hover:underline">
                      {{ device.name }}
                    </NuxtLink>
                    <p class="truncate text-xs text-muted">
                      {{ device.last_seen_at === null ? 'Never seen' : `Last seen ${formatDateTime(device.last_seen_at)}` }}
                    </p>
                  </div>
                  <UBadge :color="ATTENTION_LABELS[device.reason].color" variant="subtle" size="sm">
                    {{ ATTENTION_LABELS[device.reason].label }}
                  </UBadge>
                </li>
              </ul>
            </section>

            <section class="rounded-lg border border-default p-4">
              <h2 class="mb-3 text-sm font-semibold text-highlighted">
                Noisiest rules ({{ overview.window_hours }}h)
              </h2>
              <p v-if="overview.alerts.top_rules.length === 0" class="py-6 text-center text-sm text-muted">
                No alert opened in this window.
              </p>
              <ul v-else class="divide-y divide-default">
                <li v-for="rule in overview.alerts.top_rules" :key="rule.id" class="flex items-center justify-between gap-2 py-2 text-sm">
                  <span class="truncate text-highlighted">{{ rule.name }}</span>
                  <span class="tabular-nums text-muted">{{ rule.opened }} opened</span>
                </li>
              </ul>
            </section>
          </div>

          <section v-if="overview.pipeline" class="rounded-lg border border-default p-4">
            <h2 class="mb-3 text-sm font-semibold text-highlighted">
              Rule evaluation and webhook delivery
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <div>
                <p class="text-xs text-muted">
                  Evaluation latency p50 / p95 (1h)
                </p>
                <p class="mt-1 text-lg font-semibold text-highlighted tabular-nums">
                  {{ overview.pipeline.evaluation_latency_p50_ms ?? '—' }} / {{ overview.pipeline.evaluation_latency_p95_ms ?? '—' }} ms
                </p>
              </div>
              <div>
                <p class="text-xs text-muted">
                  Pending evaluation jobs
                </p>
                <p class="mt-1 text-lg font-semibold text-highlighted tabular-nums">
                  {{ overview.pipeline.pending_evaluation_jobs }}
                  <span v-if="overview.pipeline.oldest_pending_job_age_s !== null" class="text-sm font-normal text-muted">oldest {{ overview.pipeline.oldest_pending_job_age_s }}s</span>
                </p>
              </div>
              <div>
                <p class="text-xs text-muted">
                  Outbox waiting for fan-out
                </p>
                <p class="mt-1 text-lg font-semibold text-highlighted tabular-nums">
                  {{ overview.pipeline.outbox_pending }}
                  <span v-if="overview.pipeline.oldest_outbox_age_s !== null" class="text-sm font-normal text-muted">oldest {{ overview.pipeline.oldest_outbox_age_s }}s</span>
                </p>
              </div>
              <div>
                <p class="text-xs text-muted">
                  Deliveries ({{ overview.window_hours }}h)
                </p>
                <div class="mt-1 flex flex-wrap gap-1">
                  <UBadge
                    v-for="(count, deliveryStatus) in overview.pipeline.deliveries"
                    :key="deliveryStatus"
                    :color="deliveryStatus === 'succeeded' ? 'success' : deliveryStatus === 'exhausted' || deliveryStatus === 'failed' ? 'error' : 'neutral'"
                    variant="subtle"
                    size="sm"
                  >
                    {{ deliveryStatus }} {{ count }}
                  </UBadge>
                </div>
              </div>
            </div>

            <div v-if="overview.pipeline.failing_endpoints.length > 0" class="mt-4">
              <p class="mb-2 text-xs font-medium text-muted">
                Failing endpoints
              </p>
              <ul class="divide-y divide-default rounded-md border border-default">
                <li v-for="endpoint in overview.pipeline.failing_endpoints" :key="endpoint.id" class="flex items-center gap-3 px-3 py-2 text-sm">
                  <UIcon name="i-lucide-triangle-alert" class="size-4 shrink-0 text-error" />
                  <span class="font-medium text-highlighted">{{ endpoint.name }}</span>
                  <span class="min-w-0 flex-1 truncate text-xs text-muted">{{ endpoint.last_error ?? '' }}</span>
                  <span class="tabular-nums text-muted">{{ endpoint.failed }} failed</span>
                </li>
              </ul>
            </div>
          </section>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
