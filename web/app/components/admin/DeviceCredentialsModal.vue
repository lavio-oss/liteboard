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
const char* LB_MQTT_HOST = "${credential.value.mqtt.host}";
const uint16_t LB_MQTT_PORT = ${credential.value.mqtt.port};
const bool LB_MQTT_TLS = ${credential.value.mqtt.tls};
const char* LB_TELEMETRY_TOPIC = "${credential.value.mqtt.topics.telemetry}";`
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
    :ui="{ content: 'max-w-3xl' }"
  >
    <template #title>
      <div class="flex min-w-0 items-center gap-3">
        <div class="min-w-0">
          <p class="font-semibold text-highlighted">
            Device credentials
          </p>
          <p class="truncate text-sm text-muted">
            {{ props.device?.name ?? 'Device' }}
          </p>
        </div>
        <UBadge
          v-if="credential !== null"
          class="ml-auto capitalize"
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

      <div v-else-if="credential !== null" class="space-y-4">
        <section class="space-y-3 rounded-lg border border-default p-4">
          <div>
            <h3 class="text-sm font-semibold text-highlighted">
              Access token
            </h3>
            <p class="mt-1 text-sm text-muted">
              Use this token to authenticate the device with LiteBoard.
            </p>
          </div>
          <div class="flex min-w-0 items-center gap-2">
            <UInput
              :model-value="credential.access_token"
              :type="tokenVisible ? 'text' : 'password'"
              readonly
              class="min-w-0 flex-1 font-mono"
            />
            <UButton
              :label="tokenVisible ? 'Hide' : 'Show'"
              color="neutral"
              variant="outline"
              size="sm"
              class="shrink-0"
              @click="tokenVisible = !tokenVisible"
            />
            <UButton
              label="Copy"
              color="neutral"
              variant="outline"
              size="sm"
              class="shrink-0"
              @click="copyText(credential.access_token, 'Access token')"
            />
          </div>
        </section>

        <section class="space-y-3 rounded-lg border border-default p-4">
          <div>
            <h3 class="text-sm font-semibold text-highlighted">
              MQTT connection
            </h3>
            <p class="mt-1 text-sm text-muted">
              Use these broker settings with certificate-chain and hostname verification enabled.
            </p>
          </div>
          <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt class="text-muted">
                Host
              </dt>
              <dd class="mt-1 break-all font-mono text-highlighted">
                {{ credential.mqtt.host }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Port
              </dt>
              <dd class="mt-1 font-mono text-highlighted">
                {{ credential.mqtt.port }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Transport
              </dt>
              <dd class="mt-1 text-highlighted">
                {{ credential.mqtt.tls ? 'TLS enabled' : 'TLS disabled' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Telemetry topic
              </dt>
              <dd class="mt-1 break-all font-mono text-highlighted">
                {{ credential.mqtt.topics.telemetry }}
              </dd>
            </div>
          </dl>
        </section>

        <section class="space-y-3 rounded-lg border border-default p-4">
          <div>
            <h3 class="text-sm font-semibold text-highlighted">
              TLS CA certificate
            </h3>
            <p class="mt-1 text-sm text-muted">
              Install this public certificate on your device to verify the LiteBoard MQTT broker over TLS (port 8883).
            </p>
          </div>
          <UButton
            label="Download MQTT CA (.txt)"
            color="neutral"
            variant="outline"
            @click="downloadMqttCaCertificate"
          />
        </section>

        <section class="space-y-3 rounded-lg border border-default p-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="text-sm font-semibold text-highlighted">
                Firmware config
              </h3>
              <p class="mt-1 text-sm text-muted">
                Device connection values ready to add to your firmware.
              </p>
            </div>
            <UButton
              label="Copy config"
              color="neutral"
              variant="outline"
              size="sm"
              @click="copyText(firmwareConfig, 'Firmware config')"
            />
          </div>
          <pre class="max-h-52 overflow-auto rounded-lg bg-elevated/50 p-3 font-mono text-xs leading-relaxed text-highlighted">{{ firmwareConfig }}</pre>
        </section>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center gap-2">
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
            color="neutral"
            variant="outline"
            size="sm"
            :loading="actionLoading"
            @click="rotateToken"
          />
        </div>

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
