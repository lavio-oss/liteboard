<script setup lang="ts">
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import type { Project } from '~/types/admin'

const props = defineProps<{
  tenantId: string
  project: Project
  canManageQuota: boolean
}>()

const emit = defineEmits<{ updated: [] }>()
const toast = useToast()
const { updateProjectDeviceQuota } = useAdmin()

const actionLoading = ref(false)
const quota = ref<number | undefined>(undefined)
const unlimited = ref(false)

const deviceCount = computed(() => props.project.device_count)

const isQuotaDirty = computed(() => {
  const originalQuota = props.project.max_devices_quota ?? null
  const currentQuota = unlimited.value ? null : (quota.value ?? null)

  return currentQuota !== originalQuota
})

const quotaValidationError = computed<string | undefined>(() => {
  if (unlimited.value) {
    return undefined
  }
  if (quota.value === undefined || quota.value === null) {
    return 'Enter a device quota.'
  }
  if (quota.value < deviceCount.value) {
    return `Quota cannot be lower than existing devices (${deviceCount.value}).`
  }

  return undefined
})

const isFull = computed(() => !unlimited.value && deviceCount.value >= (quota.value ?? 0))

function onUnlimitedChange(value: boolean | 'indeterminate'): void {
  if (!value && (quota.value === undefined || quota.value === null)) {
    quota.value = props.project.max_devices_quota ?? Math.max(deviceCount.value, 1)
  }
}

async function saveQuota(): Promise<void> {
  if (!props.canManageQuota) {
    throw new Error('Tenant administrators are required to change the project device quota.')
  }
  if (quotaValidationError.value) {
    toast.add({ title: 'Invalid device quota', description: quotaValidationError.value, color: 'warning' })
    return
  }

  actionLoading.value = true

  try {
    const updatedProject = await updateProjectDeviceQuota(
      props.tenantId,
      props.project.id,
      unlimited.value ? null : (quota.value ?? null)
    )
    quota.value = updatedProject.max_devices_quota ?? undefined
    unlimited.value = updatedProject.max_devices_quota === null
    emit('updated')
    toast.add({ title: 'Device quota saved', description: 'The project limit has been updated.', color: 'success' })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to save device quota', description: error.message, color: 'error' })
  } finally {
    actionLoading.value = false
  }
}

watch(
  () => [props.tenantId, props.project.id],
  () => {
    quota.value = props.project.max_devices_quota ?? undefined
    unlimited.value = props.project.max_devices_quota === null
  },
  { immediate: true }
)
</script>

<template>
  <section class="space-y-3 rounded-lg border border-default p-4">
    <div class="flex items-baseline justify-between gap-4">
      <h3 class="text-sm font-semibold text-highlighted">
        Device limit
      </h3>
      <span class="text-sm" :class="isFull ? 'text-error' : 'text-muted'">
        {{ unlimited ? `${deviceCount} devices · Unlimited` : `${deviceCount} / ${quota ?? 0} devices` }}
      </span>
    </div>

    <UProgress
      v-if="!unlimited"
      :model-value="Math.min(deviceCount, quota ?? 0)"
      :max="Math.max(quota ?? 0, 1)"
      size="sm"
      :color="isFull ? 'error' : 'primary'"
    />

    <div class="flex flex-wrap items-center gap-3 pt-1">
      <USwitch
        v-model="unlimited"
        label="Unlimited"
        :disabled="!canManageQuota || actionLoading"
        @update:model-value="onUnlimitedChange"
      />
      <UInputNumber
        v-if="!unlimited"
        v-model="quota"
        :min="deviceCount"
        :disabled="!canManageQuota || actionLoading"
        size="sm"
        class="w-32"
      />
      <UButton
        v-if="canManageQuota && isQuotaDirty"
        label="Save"
        size="sm"
        class="ml-auto"
        :disabled="!!quotaValidationError"
        :loading="actionLoading"
        @click="saveQuota"
      />
    </div>

    <p v-if="quotaValidationError" class="text-xs text-error">
      {{ quotaValidationError }}
    </p>
  </section>
</template>
