<script setup lang="ts">
import type { Alert } from '~/types/alerting'

const props = withDefaults(defineProps<{
  alert: Alert | null
  deviceName: string
  ruleName: string
  projectName?: string
  canAcknowledge: boolean
  loading?: boolean
}>(), {
  projectName: undefined,
  loading: false
})

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  acknowledge: []
}>()

const CLEAR_REASON_LABELS = {
  condition_cleared: 'Recovered',
  device_offline: 'Device went offline',
  rule_archived: 'Rule archived'
} as const
</script>

<template>
  <UModal
    v-model:open="open"
    :ui="{ content: 'max-w-3xl' }"
  >
    <template #title>
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-bell-ring" class="size-5 shrink-0 text-error" />
        <span>{{ props.ruleName }}</span>
        <AlertingSeverityBadge v-if="props.alert !== null" :severity="props.alert.severity" />
        <UBadge
          v-if="props.alert !== null"
          class="capitalize"
          :color="props.alert.cleared_at === null ? 'error' : 'success'"
          variant="subtle"
        >
          {{ props.alert.cleared_at === null ? 'active' : 'cleared' }}
        </UBadge>
      </div>
    </template>

    <template #body>
      <div v-if="props.alert !== null" class="space-y-4">
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div class="space-y-1">
            <span class="text-xs text-muted">Device</span>
            <p class="font-medium text-highlighted">
              {{ props.deviceName }}
            </p>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Project</span>
            <p class="text-highlighted">
              {{ props.projectName ?? '—' }}
            </p>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Opened at</span>
            <p class="text-highlighted">
              {{ formatDateTime(props.alert.opened_at) }}
            </p>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Last seen</span>
            <p class="text-highlighted">
              {{ formatDateTime(props.alert.last_seen_at) }}
            </p>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Cleared at</span>
            <p class="text-highlighted">
              {{ formatDateTime(props.alert.cleared_at) }}
              <span v-if="props.alert.clear_reason !== null" class="text-muted">· {{ CLEAR_REASON_LABELS[props.alert.clear_reason] }}</span>
            </p>
          </div>

          <div class="space-y-1">
            <span class="text-xs text-muted">Acknowledged at</span>
            <p class="text-highlighted">
              {{ formatDateTime(props.alert.acknowledged_at) }}
            </p>
          </div>

          <div class="col-span-2 space-y-1">
            <span class="text-xs text-muted">Evidence</span>
            <pre class="overflow-x-auto rounded-md bg-elevated p-3 text-xs">{{ JSON.stringify(props.alert.evidence, null, 2) }}</pre>
          </div>

          <div class="col-span-2 space-y-2">
            <span class="text-xs text-muted">History</span>
            <UTimeline
              v-if="(props.alert.events ?? []).length > 0"
              :items="(props.alert.events ?? []).map(event => ({
                title: event.type.replace('_', ' '),
                date: formatDateTime(event.occurred_at),
                description: Object.keys(event.data).length > 0 ? JSON.stringify(event.data) : undefined
              }))"
              size="xs"
            />
            <p v-else class="text-muted">
              {{ props.loading ? 'Loading history…' : 'No history recorded.' }}
            </p>
          </div>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between">
        <div v-if="props.canAcknowledge && props.alert?.acknowledged_at === null">
          <UButton
            label="Acknowledge"
            icon="i-lucide-check"
            color="neutral"
            variant="outline"
            size="sm"
            :loading="props.loading"
            @click="emit('acknowledge')"
          />
        </div>
        <div v-else />

        <UButton
          label="Close"
          color="neutral"
          variant="subtle"
          @click="open = false"
        />
      </div>
    </template>
  </UModal>
</template>
