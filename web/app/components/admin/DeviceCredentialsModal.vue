<script setup lang="ts">
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useMqttCaCertificateDownload } from '~/composables/useMqttCaCertificateDownload'
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
const downloadMqttCaCertificate = useMqttCaCertificateDownload()
const {
  loadDeviceCredentials,
  rotateDeviceCredentials,
  updateDevice,
  updateDeviceCredentials
} = useAdmin()

const credential = ref<DeviceCredential | null>(null)
const loading = ref(false)
const actionLoading = ref(false)
const error = ref<ApiError | null>(null)
const tokenVisible = ref(false)

const tlsEndpoint = computed(() => {
  const endpoint = credential.value?.mqtt.endpoints.find(candidate => candidate.tls)

  if (credential.value !== null && endpoint === undefined) {
    throw new Error('The credential must expose a TLS MQTT endpoint.')
  }

  return endpoint
})

const plaintextEndpoint = computed(() => credential.value?.mqtt.endpoints.find(candidate => !candidate.tls))
// The device decides: the API lists the plaintext endpoint only for devices that allow it.
const selectedEndpoint = computed(() => plaintextEndpoint.value ?? tlsEndpoint.value)

const firmwareConfig = computed(() => {
  if (credential.value === null || selectedEndpoint.value === undefined) {
    return ''
  }

  return `const char* LB_PROJECT_ID = "${credential.value.project_id}";
const char* LB_DEVICE_ID = "${credential.value.device_id}";
const char* LB_ACCESS_TOKEN = "${credential.value.access_token}";
const char* LB_HOST = "";
// ${selectedEndpoint.value.tls ? 'TLS with a verified server certificate.' : 'Plaintext playground: the access token is sent unencrypted.'}
constexpr uint16_t LB_PORT = ${selectedEndpoint.value.port};
#define LB_MQTT_TLS ${selectedEndpoint.value.tls ? 1 : 0}
const char* LB_TELEMETRY_TOPIC = "${credential.value.mqtt.topics.telemetry}";`
})

async function fetchCredentials(): Promise<void> {
  if (!open.value || props.tenantId === null || props.device === null) {
    return
  }

  loading.value = true
  error.value = null
  tokenVisible.value = false

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
      tokenVisible.value = false
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
    tokenVisible.value = false
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

async function setAllowPlaintext(allowPlaintext: boolean): Promise<void> {
  if (props.tenantId === null || props.device === null) {
    throw new Error('A tenant and device are required to change the plaintext setting.')
  }

  actionLoading.value = true

  try {
    await updateDevice(props.tenantId, props.device.project_id, props.device.id, { allow_plaintext: allowPlaintext })
    credential.value = await loadDeviceCredentials(props.tenantId, props.device.project_id, props.device.id)
    emit('updated')
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    toast.add({
      title: 'Unable to update the connection setting',
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
    :description="props.device?.name"
    :ui="{ content: 'max-w-3xl' }"
  >
    <template #title>
      <div class="flex items-center gap-3">
        <span>Credentials</span>
        <div
          v-for="endpoint in selectedEndpoint === undefined ? [] : [selectedEndpoint]"
          :key="endpoint.port"
          class="inline-flex items-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-1 text-sm font-normal"
        >
          <span class="font-mono font-semibold text-highlighted">{{ endpoint.port }}</span>
          <span class="text-muted/60">·</span>
          <UBadge
            :color="endpoint.tls ? 'success' : 'warning'"
            variant="subtle"
            size="sm"
          >
            {{ endpoint.tls ? 'TLS' : 'No TLS' }}
          </UBadge>
        </div>
      </div>
    </template>

    <template #body>
      <div v-if="loading" class="py-8 text-center text-sm text-muted">
        Loading...
      </div>

      <UAlert
        v-else-if="error !== null"
        color="error"
        variant="subtle"
        title="Unable to load credentials"
        :description="error.message"
      />

      <div v-else-if="credential !== null" class="space-y-5">
        <div class="space-y-1.5">
          <span class="block text-sm text-muted">Access token</span>
          <div class="flex items-center gap-1">
            <UInput
              :model-value="credential.access_token"
              :type="tokenVisible ? 'text' : 'password'"
              readonly
              class="min-w-0 flex-1 font-mono"
            />
            <UButton
              :icon="tokenVisible ? 'i-lucide-eye-off' : 'i-lucide-eye'"
              color="neutral"
              variant="ghost"
              :aria-label="tokenVisible ? 'Hide token' : 'Show token'"
              @click="tokenVisible = !tokenVisible"
            />
            <UButton
              icon="i-lucide-copy"
              color="neutral"
              variant="ghost"
              aria-label="Copy token"
              @click="copyText(credential.access_token, 'Access token')"
            />
          </div>
        </div>

        <div class="space-y-1.5">
          <span class="block text-sm text-muted">Topic</span>
          <div class="flex items-center gap-1">
            <div class="min-w-0 flex-1 overflow-x-auto rounded-md border border-default bg-elevated/40 px-3 py-1.5 font-mono text-sm text-highlighted whitespace-nowrap">
              {{ credential.mqtt.topics.telemetry }}
            </div>
            <UButton
              icon="i-lucide-copy"
              color="neutral"
              variant="ghost"
              aria-label="Copy topic"
              @click="copyText(credential.mqtt.topics.telemetry, 'Topic')"
            />
          </div>
        </div>

        <USwitch
          v-if="props.canManage"
          :model-value="plaintextEndpoint !== undefined"
          label="Allow connection without TLS"
          :disabled="actionLoading"
          @update:model-value="setAllowPlaintext"
        />

        <UCollapsible>
          <UButton
            label="Firmware config"
            color="neutral"
            variant="link"
            trailing-icon="i-lucide-chevron-down"
            class="px-0"
          />

          <template #content>
            <div class="mt-2 space-y-2">
              <pre class="max-h-48 overflow-auto rounded-lg bg-elevated/50 p-3 font-mono text-sm leading-relaxed text-highlighted">{{ firmwareConfig }}</pre>
              <div class="flex gap-2">
                <UButton
                  label="Copy config"
                  icon="i-lucide-copy"
                  color="neutral"
                  variant="outline"
                  @click="copyText(firmwareConfig, 'Firmware config')"
                />
                <UButton
                  v-if="selectedEndpoint?.tls"
                  label="MQTT CA"
                  icon="i-lucide-download"
                  color="neutral"
                  variant="outline"
                  @click="downloadMqttCaCertificate"
                />
              </div>
            </div>
          </template>
        </UCollapsible>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center gap-2">
        <template v-if="credential !== null && props.canManage">
          <UButton
            :label="credential.status === 'active' ? 'Revoke' : 'Activate'"
            :color="credential.status === 'active' ? 'error' : 'success'"
            variant="ghost"
            :loading="actionLoading"
            @click="toggleCredentialStatus"
          />
          <UButton
            label="Rotate"
            color="neutral"
            variant="ghost"
            :loading="actionLoading"
            @click="rotateToken"
          />
        </template>

        <UButton
          label="Close"
          color="neutral"
          variant="subtle"
          class="ml-auto"
          @click="open = false"
        />
      </div>
    </template>
  </UModal>
</template>
