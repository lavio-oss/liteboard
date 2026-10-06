<script setup lang="ts">
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import ProjectProvisioningProfileSection from '~/components/ProjectProvisioningProfileSection.vue'
import type { Project } from '~/types/admin'

const props = defineProps<{
  tenantId: string | null
  project: Project | null
  canManageQuota: boolean
}>()

const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ updated: [] }>()
const toast = useToast()
const requestUrl = useRequestURL()
const { updateProjectDeviceQuota } = useAdmin()

const actionLoading = ref(false)
const quota = ref<number | undefined>(undefined)
const unlimited = ref(false)

const deviceCount = computed(() => props.project?.device_count ?? 0)

const isQuotaDirty = computed(() => {
  if (props.project === null) {
    return false
  }

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

const quotaBadge = computed(() => {
  if (unlimited.value) {
    return { text: 'Unlimited', color: 'neutral' as const }
  }

  const max = quota.value ?? 0

  if (deviceCount.value >= max) {
    return { text: `${deviceCount.value}/${max} maximum (Full)`, color: 'error' as const }
  }

  return { text: `${deviceCount.value}/${max} maximum`, color: 'neutral' as const }
})

const bootstrapEndpoint = computed(() => new URL(
  '/api/device-provisioning/bootstrap',
  requestUrl.origin
).toString())

function onUnlimitedChange(value: boolean | 'indeterminate'): void {
  if (!value && (quota.value === undefined || quota.value === null)) {
    quota.value = props.project?.max_devices_quota ?? Math.max(deviceCount.value, 1)
  }
}

async function runAction(
  action: (tenantId: string, projectId: string) => Promise<void>,
  fallbackErrorTitle: string
): Promise<void> {
  if (props.tenantId === null || props.project === null) {
    return
  }

  actionLoading.value = true

  try {
    await action(props.tenantId, props.project.id)
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: fallbackErrorTitle, description: error.message, color: 'error' })
  } finally {
    actionLoading.value = false
  }
}

function load(): void {
  if (!open.value || props.tenantId === null || props.project === null) {
    return
  }

  quota.value = props.project.max_devices_quota ?? undefined
  unlimited.value = props.project.max_devices_quota === null
}

async function saveQuota(): Promise<void> {
  if (!props.canManageQuota) {
    throw new Error('Tenant administrators are required to change the project device quota.')
  }
  if (quotaValidationError.value) {
    toast.add({ title: 'Invalid device quota', description: quotaValidationError.value, color: 'warning' })
    return
  }

  const quotaToSave = unlimited.value ? null : (quota.value ?? null)

  await runAction(async (tenantId, projectId) => {
    const updatedProject = await updateProjectDeviceQuota(tenantId, projectId, quotaToSave)
    quota.value = updatedProject.max_devices_quota ?? undefined
    unlimited.value = updatedProject.max_devices_quota === null
    emit('updated')
    toast.add({ title: 'Device quota saved', description: 'The project limit has been updated.', color: 'success' })
  }, 'Unable to save device quota')
}

watch(
  () => [open.value, props.tenantId, props.project?.id],
  () => {
    load()
  },
  { immediate: true }
)
</script>

<template>
  <UModal
    v-model:open="open"
    :title="project ? `Device provisioning: ${project.name}` : 'Device provisioning'"
    description="Manage project device quotas and automated device provisioning profiles."
    :ui="{ content: 'max-w-3xl' }"
  >
    <template #body>
      <div v-if="project !== null && tenantId !== null" class="space-y-6">
        <section class="space-y-4">
          <div class="flex items-center justify-between gap-4">
            <div>
              <h3 class="text-sm font-semibold text-highlighted">
                Project device quota
              </h3>
              <p class="text-sm text-muted">
                {{ deviceCount }} existing device{{ deviceCount === 1 ? '' : 's' }}.
              </p>
            </div>
            <UBadge :color="quotaBadge.color" variant="subtle">
              {{ quotaBadge.text }}
            </UBadge>
          </div>

          <div class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center gap-3">
              <UCheckbox
                v-model="unlimited"
                label="No limit"
                :disabled="!canManageQuota || actionLoading"
                @update:model-value="onUnlimitedChange"
              />
              <UInputNumber
                v-if="!unlimited"
                v-model="quota"
                :min="deviceCount"
                :disabled="!canManageQuota || actionLoading"
                class="w-48"
              />
              <UButton
                v-if="canManageQuota"
                label="Save quota"
                color="success"
                :variant="isQuotaDirty ? 'solid' : 'subtle'"
                :disabled="!isQuotaDirty || !!quotaValidationError"
                :loading="actionLoading"
                @click="saveQuota"
              />
            </div>
            <p v-if="quotaValidationError" class="text-xs text-error">
              {{ quotaValidationError }}
            </p>
          </div>
        </section>

        <USeparator />

        <ProjectProvisioningProfileSection
          :key="project.id"
          :active="open"
          :bootstrap-endpoint="bootstrapEndpoint"
          :project="project"
          :show-quota="false"
          :tenant-id="tenantId"
          @updated="emit('updated')"
        />
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-end">
        <UButton
          label="Close"
          color="neutral"
          variant="outline"
          @click="open = false"
        />
      </div>
    </template>
  </UModal>
</template>
