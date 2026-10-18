<script setup lang="ts">
import type { WebhookDelivery, WebhookEndpoint } from '~/types/alerting'

const props = withDefaults(defineProps<{
  endpoint: WebhookEndpoint | null
  deliveries: WebhookDelivery[]
  canRedeliver: boolean
  loading?: boolean
  redeliveringId?: string | null
}>(), {
  loading: false,
  redeliveringId: null
})

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  redeliver: [deliveryId: string]
  refresh: []
}>()

const STATUS_COLORS = {
  pending: 'info',
  in_flight: 'info',
  succeeded: 'success',
  failed: 'error',
  exhausted: 'warning'
} as const
</script>

<template>
  <UModal
    v-model:open="open"
    :ui="{ content: 'max-w-3xl' }"
  >
    <template #title>
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-webhook" class="size-5 shrink-0 text-primary" />
        <span>{{ props.endpoint?.name ?? 'Deliveries' }}</span>
      </div>
    </template>

    <template #description>
      <span class="font-mono break-all">{{ props.endpoint?.url }}</span>
    </template>

    <template #body>
      <p v-if="!props.loading && props.deliveries.length === 0" class="text-sm text-muted">
        No deliveries yet. Events are delivered after an alert opens, clears or is acknowledged.
      </p>

      <ul class="space-y-3">
        <li
          v-for="delivery in props.deliveries"
          :key="delivery.id"
          class="rounded-lg border border-default p-3"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <UBadge
                class="capitalize"
                :color="STATUS_COLORS[delivery.status]"
                variant="subtle"
              >
                {{ delivery.status.replace('_', ' ') }}
              </UBadge>
              <span class="font-mono text-xs">{{ delivery.event_type ?? delivery.outbox_event_id }}</span>
            </div>
            <UButton
              v-if="props.canRedeliver && (delivery.status === 'failed' || delivery.status === 'exhausted')"
              label="Redeliver"
              icon="i-lucide-send"
              size="xs"
              :loading="props.redeliveringId === delivery.id"
              @click="emit('redeliver', delivery.id)"
            />
          </div>

          <p class="mt-2 text-xs text-muted">
            {{ delivery.attempts }} attempt(s) this cycle
            <template v-if="delivery.status === 'pending' && delivery.attempts > 0">
              · next retry {{ formatDateTime(delivery.next_attempt_at) }}
            </template>
            <template v-if="delivery.delivered_at !== null">
              · delivered {{ formatDateTime(delivery.delivered_at) }}
            </template>
          </p>
          <p v-if="delivery.last_error !== null" class="mt-1 font-mono text-xs break-all text-error">
            {{ delivery.last_error }}
          </p>

          <details v-if="(delivery.attempt_history ?? []).length > 0" class="mt-2 text-xs">
            <summary class="cursor-pointer text-muted">
              Attempt history
            </summary>
            <table class="mt-2 w-full text-left">
              <thead class="text-muted">
                <tr>
                  <th class="pr-2 font-normal">
                    #
                  </th>
                  <th class="pr-2 font-normal">
                    Started
                  </th>
                  <th class="pr-2 font-normal">
                    Status
                  </th>
                  <th class="pr-2 font-normal">
                    Duration
                  </th>
                  <th class="font-normal">
                    Error
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="attempt in delivery.attempt_history" :key="attempt.attempt_no">
                  <td class="pr-2">
                    {{ attempt.attempt_no }}
                  </td>
                  <td class="pr-2">
                    {{ formatDateTime(attempt.started_at) }}
                  </td>
                  <td class="pr-2">
                    {{ attempt.status_code ?? '—' }}
                  </td>
                  <td class="pr-2">
                    {{ attempt.duration_ms }} ms
                  </td>
                  <td class="font-mono break-all">
                    {{ attempt.error ?? '—' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </details>
        </li>
      </ul>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between">
        <UButton
          label="Reload"
          icon="i-lucide-rotate-ccw"
          color="neutral"
          variant="outline"
          size="sm"
          :loading="props.loading"
          @click="emit('refresh')"
        />
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
