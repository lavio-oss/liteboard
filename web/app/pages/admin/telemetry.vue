<script setup lang="ts">
import { ApiError } from '~/composables/useApi'
import { useTelemetry } from '~/composables/useTelemetry'
import type { TelemetryUnresolvedMessage } from '~/types/telemetry'

definePageMeta({
  middleware: 'auth'
})

const toast = useToast()
const { user } = useAuth()
const { loadUnresolvedMessage, loadUnresolvedMessages } = useTelemetry()
const isPlatformAdmin = computed(() => user.value?.system_role === 'platform_admin')
const messages = ref<TelemetryUnresolvedMessage[]>([])
const selected = ref<TelemetryUnresolvedMessage | null>(null)
const processingQuery = ref('')
const loading = ref(false)

const reasonLabels: Record<TelemetryUnresolvedMessage['reason'], string> = {
  invalid_topic: 'Invalid topic',
  device_not_found: 'Device not found',
  project_mismatch: 'Project mismatch'
}

async function run(action: () => Promise<void>, title: string): Promise<void> {
  loading.value = true

  try {
    await action()
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title, description: error.message, color: 'error' })
  } finally {
    loading.value = false
  }
}

function reload(): Promise<void> {
  return run(async () => {
    messages.value = await loadUnresolvedMessages(100)
  }, 'Unresolved messages could not be loaded')
}

function findByProcessingId(): Promise<void> {
  return run(async () => {
    selected.value = await loadUnresolvedMessage(processingQuery.value)
  }, 'Unresolved message not found')
}

onMounted(() => {
  if (isPlatformAdmin.value) {
    void reload()
  }
})
</script>

<template>
  <UDashboardPanel id="admin-telemetry" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Unresolved Telemetry">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="isPlatformAdmin"
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            :loading="loading"
            aria-label="Refresh unresolved messages"
            @click="reload"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="!isPlatformAdmin"
        color="warning"
        variant="subtle"
        title="Only platform administrators can view unresolved telemetry."
      />

      <div v-else class="grid gap-4 lg:grid-cols-2">
        <UCard>
          <template #header>
            <p class="text-sm text-muted">
              Messages whose topic did not match an existing device in that project. Payloads are never stored.
            </p>
          </template>

          <p v-if="messages.length === 0" class="text-sm text-muted">
            No unresolved messages.
          </p>
          <ul v-else class="divide-y divide-default">
            <li v-for="message in messages" :key="message.processing_id">
              <button
                type="button"
                class="flex w-full items-center justify-between gap-2 py-2 text-left text-sm hover:bg-elevated"
                @click="selected = message"
              >
                <span class="truncate font-mono">{{ message.topic }}</span>
                <span class="flex shrink-0 items-center gap-2">
                  <span class="text-xs text-muted">{{ formatDateTime(message.received_at) }}</span>
                  <UBadge color="warning" variant="subtle">
                    {{ reasonLabels[message.reason] }}
                  </UBadge>
                </span>
              </button>
            </li>
          </ul>
        </UCard>

        <UCard>
          <template #header>
            <form class="flex gap-2" @submit.prevent="findByProcessingId">
              <UInput
                v-model="processingQuery"
                placeholder="Processing ID"
                class="flex-1"
                aria-label="Processing ID"
              />
              <UButton
                type="submit"
                label="Find"
                :loading="loading"
                :disabled="processingQuery === ''"
              />
            </form>
          </template>

          <p v-if="selected === null" class="text-sm text-muted">
            Select a message or search by processing ID.
          </p>
          <dl v-else class="space-y-2 text-sm">
            <div class="flex justify-between gap-2">
              <dt class="text-muted">
                Processing ID
              </dt>
              <dd class="font-mono text-xs">
                {{ selected.processing_id }}
              </dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-muted">
                Topic
              </dt>
              <dd class="break-all font-mono text-xs">
                {{ selected.topic }}
              </dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-muted">
                Reason
              </dt>
              <dd>{{ reasonLabels[selected.reason] }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-muted">
                Payload size
              </dt>
              <dd>{{ selected.payload_size }} bytes</dd>
            </div>
            <div>
              <dt class="mb-1 text-muted">
                Stages
              </dt>
              <ol class="space-y-1 border-l border-default pl-3">
                <li v-for="stage in selected.stages" :key="stage.stage" class="flex justify-between gap-2">
                  <span>{{ stage.stage }}</span>
                  <span class="font-mono text-xs text-muted">{{ formatDateTime(stage.at) }}</span>
                </li>
              </ol>
            </div>
          </dl>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
