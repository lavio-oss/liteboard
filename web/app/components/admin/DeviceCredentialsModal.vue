<script setup lang="ts">
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import type { Device, DeviceCredential } from '~/types/admin'

const props = withDefaults(defineProps<{
  tenantId: string | null
  device: Device | null
  canManage?: boolean
}>(), {
  canManage: false
})

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  updated: []
}>()

const toast = useToast()
const {
  loadDeviceCredentials,
  rotateDeviceCredentials,
  updateDeviceCredentials
} = useAdmin()

const credential = ref<DeviceCredential | null>(null)
const loading = ref(false)
const actionLoading = ref(false)
const error = ref<ApiError | null>(null)
const tokenVisible = ref(true)

const firmwareConfig = computed(() => {
  if (credential.value === null) {
    return ''
  }

  return `const char* LB_PROJECT_ID = "${credential.value.project_id}";
const char* LB_DEVICE_ID = "${credential.value.device_id}";
const char* LB_ACCESS_TOKEN = "${credential.value.access_token}";
const char* LB_TELEMETRY_TOPIC = "${credential.value.mqtt_topics.telemetry}";`
})

async function fetchCredentials(): Promise<void> {
  if (!open.value || props.tenantId === null || props.device === null) {
    return
  }

  loading.value = true
  error.value = null
  tokenVisible.value = true

  try {
    credential.value = await loadDeviceCredentials(props.tenantId, props.device.project_id, props.device.id)
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    error.value = requestError
  } finally {
    loading.value = false
  }
}

watch(
  () => [open.value, props.tenantId, props.device?.id],
  () => {
    if (open.value) {
      void fetchCredentials()
    } else {
      credential.value = null
      error.value = null
      tokenVisible.value = true
    }
  },
  { immediate: true }
)

async function copyText(value: string, label: string): Promise<void> {
  await navigator.clipboard.writeText(value)
  toast.add({
    title: `${label} copied`,
    description: 'Copied to your clipboard.',
    color: 'success'
  })
}

async function rotateToken(): Promise<void> {
  if (props.tenantId === null || props.device === null) {
    throw new Error('A tenant and device are required to rotate credentials.')
  }

  actionLoading.value = true

  try {
    credential.value = await rotateDeviceCredentials(props.tenantId, props.device.project_id, props.device.id)
    tokenVisible.value = true
    emit('updated')
    toast.add({
      title: 'Access token rotated',
      description: 'A new access token was generated and activated.',
      color: 'success'
    })
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    toast.add({
      title: 'Unable to rotate access token',
      description: requestError.message,
      color: 'error'
    })
  } finally {
    actionLoading.value = false
  }
}

async function toggleCredentialStatus(): Promise<void> {
  if (props.tenantId === null || props.device === null || credential.value === null) {
    throw new Error('A tenant, device, and loaded credential are required.')
  }

  const nextStatus = credential.value.status === 'active' ? 'revoked' : 'active'
  actionLoading.value = true

  try {
    credential.value = await updateDeviceCredentials(props.tenantId, props.device.project_id, props.device.id, {
      status: nextStatus
    })
    emit('updated')
    toast.add({
      title: nextStatus === 'active' ? 'Credential activated' : 'Credential revoked',
      description: nextStatus === 'active'
        ? 'The device can now connect to LiteBoard.'
        : 'The device is now blocked from connecting to LiteBoard.',
      color: 'success'
    })
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    toast.add({
      title: 'Unable to update credential status',
      description: requestError.message,
      color: 'error'
    })
  } finally {
    actionLoading.value = false
  }
}
</script>

<template>
  <UModal
    v-model:open="open"
    :ui="{ content: 'max-w-xl' }"
  >
    <template #title>
      <div class="flex items-center gap-2">
        <span>Device credentials — {{ props.device?.name ?? 'Device' }}</span>
        <UBadge
          v-if="credential !== null"
          class="capitalize"
          :color="credential.status === 'active' ? 'success' : 'error'"
          variant="subtle"
        >
          {{ credential.status }}
        </UBadge>
      </div>
    </template>

    <template #body>
      <div v-if="loading" class="py-8 text-center text-sm text-muted">
        Loading device credentials...
      </div>

      <UAlert
        v-else-if="error !== null"
        color="error"
        variant="subtle"
        title="Unable to load device credentials"
        :description="error.message"
      />

      <div v-else-if="credential !== null" class="space-y-5">
        <UFormField
          label="Access Token"
          description="Copy this token to authenticate your device with LiteBoard."
        >
          <div class="flex items-center gap-2">
            <UInput
              :model-value="credential.access_token"
              :type="tokenVisible ? 'text' : 'password'"
              readonly
              class="w-full font-mono"
            />
            <UButton
              :icon="tokenVisible ? 'i-lucide-eye-off' : 'i-lucide-eye'"
              color="neutral"
              variant="outline"
              :aria-label="tokenVisible ? 'Hide access token' : 'Show access token'"
              @click="tokenVisible = !tokenVisible"
            />
            <UButton
              label="Copy"
              icon="i-lucide-copy"
              color="primary"
              variant="subtle"
              aria-label="Copy access token"
              @click="copyText(credential.access_token, 'Access token')"
            />
          </div>
        </UFormField>

        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-highlighted">Firmware Config</span>
            <UButton
              label="Copy config"
              icon="i-lucide-copy"
              color="neutral"
              variant="ghost"
              size="xs"
              @click="copyText(firmwareConfig, 'Firmware config')"
            />
          </div>
          <pre class="overflow-x-auto rounded-lg border border-default bg-elevated/50 p-3 font-mono text-xs text-highlighted">{{ firmwareConfig }}</pre>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between">
        <div v-if="credential !== null && props.canManage" class="flex items-center gap-2">
          <UButton
            :label="credential.status === 'active' ? 'Revoke token' : 'Activate token'"
            :color="credential.status === 'active' ? 'error' : 'success'"
            variant="subtle"
            size="sm"
            :loading="actionLoading"
            @click="toggleCredentialStatus"
          />
          <UButton
            label="Rotate token"
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="outline"
            size="sm"
            :loading="actionLoading"
            @click="rotateToken"
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
