<script setup lang="ts">
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import type { Project, ProjectProvisioningProfile } from '~/types/admin'

const props = defineProps<{
  active: boolean
  bootstrapEndpoint: string
  project: Project
  showQuota: boolean
  tenantId: string
}>()

const emit = defineEmits<{ updated: [] }>()
const toast = useToast()
const {
  createProjectProvisioningProfile,
  loadProjectProvisioningProfile,
  rotateProjectProvisioningSecret,
  updateProjectProvisioningProfile
} = useAdmin()

const sessionSecretsKey = 'liteboard_provisioning_secrets'

function getCachedSecret(projectId: string): string | null {
  if (!import.meta.client) {
    return null
  }

  try {
    const raw = window.sessionStorage.getItem(sessionSecretsKey)
    if (!raw) {
      return null
    }

    const map = JSON.parse(raw) as Record<string, string>
    return typeof map[projectId] === 'string' ? map[projectId] : null
  } catch {
    return null
  }
}

function setCachedSecret(projectId: string, secret: string): void {
  if (!import.meta.client) {
    return
  }

  try {
    const raw = window.sessionStorage.getItem(sessionSecretsKey)
    const map = (raw ? JSON.parse(raw) : {}) as Record<string, string>
    map[projectId] = secret
    window.sessionStorage.setItem(sessionSecretsKey, JSON.stringify(map))
  } catch {
    // Ignore storage errors
  }
}

const profile = ref<ProjectProvisioningProfile | null>(null)
const provisionSecret = ref<string | null>(null)
const secretVisible = ref(false)
const secretDisplayValue = computed(() => provisionSecret.value ?? '••••••••••••••••••••••••••••••••')
const loading = ref(false)
const actionLoading = ref(false)
const rotateConfirmOpen = ref(false)

const profileBadge = computed(() => {
  if (profile.value === null) {
    return { text: 'Not configured', color: 'neutral' as const }
  }

  return profile.value.enabled
    ? { text: 'Enabled', color: 'success' as const }
    : { text: 'Disabled', color: 'neutral' as const }
})

const quotaFull = computed(() => props.project.max_devices_quota !== null
  && props.project.device_count >= props.project.max_devices_quota)

const quotaLabel = computed(() => props.project.max_devices_quota === null
  ? `${props.project.device_count} devices · Unlimited`
  : `${props.project.device_count}/${props.project.max_devices_quota} devices`)

function clearSensitiveState(): void {
  profile.value = null
  provisionSecret.value = null
  secretVisible.value = false
}

async function runProfileAction<T>(
  action: (tenantId: string, projectId: string) => Promise<T>,
  fallbackErrorTitle: string
): Promise<T | null> {
  actionLoading.value = true

  try {
    const result = await action(props.tenantId, props.project.id)

    return props.active ? result : null
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: fallbackErrorTitle, description: error.message, color: 'error' })

    return null
  } finally {
    actionLoading.value = false
  }
}

async function load(): Promise<void> {
  loading.value = true
  clearSensitiveState()
  provisionSecret.value = getCachedSecret(props.project.id)

  try {
    const loadedProfile = await loadProjectProvisioningProfile(props.tenantId, props.project.id)

    if (props.active) {
      profile.value = loadedProfile
    }
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to load provisioning profile', description: error.message, color: 'error' })
  } finally {
    loading.value = false
  }
}

async function createProfile(): Promise<void> {
  const result = await runProfileAction(
    createProjectProvisioningProfile,
    'Unable to create provisioning profile'
  )

  if (result === null) {
    return
  }

  profile.value = result.data
  provisionSecret.value = result.provision_secret
  setCachedSecret(props.project.id, result.provision_secret)
  secretVisible.value = true
  emit('updated')
  toast.add({
    title: 'Provisioning profile created',
    description: 'The provisioning profile is ready.',
    color: 'success'
  })
}

async function setEnabled(enabled: boolean): Promise<void> {
  const updatedProfile = await runProfileAction(
    (tenantId, projectId) => updateProjectProvisioningProfile(tenantId, projectId, enabled),
    'Unable to update provisioning'
  )

  if (updatedProfile === null) {
    return
  }

  profile.value = updatedProfile
  emit('updated')
  toast.add({
    title: enabled ? 'Provisioning enabled' : 'Provisioning disabled',
    description: enabled ? 'New devices can enroll with this profile.' : 'New enrollments are blocked.',
    color: 'success'
  })
}

async function rotateSecret(): Promise<void> {
  const result = await runProfileAction(
    rotateProjectProvisioningSecret,
    'Unable to rotate provisioning secret'
  )

  if (result === null) {
    return
  }

  profile.value = result.data
  provisionSecret.value = result.provision_secret
  setCachedSecret(props.project.id, result.provision_secret)
  secretVisible.value = true
  rotateConfirmOpen.value = false
  emit('updated')
  toast.add({
    title: 'Provisioning secret rotated',
    description: 'Devices using the previous secret can no longer enroll.',
    color: 'success'
  })
}

async function copyKey(): Promise<void> {
  if (profile.value === null) {
    return
  }

  await navigator.clipboard.writeText(profile.value.provision_key)
  toast.add({ title: 'Provision key copied', description: 'Copied to your clipboard.', color: 'success' })
}

async function copySecret(): Promise<void> {
  if (provisionSecret.value === null) {
    return
  }

  await navigator.clipboard.writeText(provisionSecret.value)
  toast.add({ title: 'Provisioning secret copied', description: 'Copied to your clipboard.', color: 'success' })
}

async function copyBootstrapEndpoint(): Promise<void> {
  await navigator.clipboard.writeText(props.bootstrapEndpoint)
  toast.add({ title: 'Bootstrap endpoint copied', description: 'Copied to your clipboard.', color: 'success' })
}

watch(
  () => [props.active, props.tenantId, props.project.id],
  ([active]) => {
    if (!active) {
      clearSensitiveState()
      return
    }

    void load()
  },
  { immediate: true }
)
</script>

<template>
  <div class="space-y-5">
    <div class="rounded-lg border border-default bg-elevated/30 p-4 space-y-4">
      <div class="flex items-center justify-between gap-4">
        <div>
          <h3 class="text-sm font-semibold text-highlighted">
            Provisioning profile
          </h3>
          <p class="text-sm text-muted">
            Enroll new devices in {{ project.name }} over HTTPS.
          </p>
        </div>
        <UBadge :color="profileBadge.color" variant="subtle">
          {{ profileBadge.text }}
        </UBadge>
      </div>

      <div v-if="showQuota" class="border-t border-default pt-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="text-sm font-medium text-highlighted">
              Device capacity
            </p>
            <p class="text-sm text-muted">
              {{ quotaLabel }}
            </p>
          </div>
          <UBadge :color="quotaFull ? 'error' : 'neutral'" variant="subtle">
            {{ quotaFull ? 'Full' : 'Read only' }}
          </UBadge>
        </div>
        <p v-if="quotaFull" class="mt-2 text-sm text-error">
          This project has reached its device limit. Contact a tenant administrator to increase the quota.
        </p>
      </div>
    </div>

    <div v-if="loading" class="space-y-3">
      <USkeleton class="h-10 w-full" />
      <USkeleton class="h-10 w-full" />
    </div>

    <template v-else>
      <UFormField label="HTTPS bootstrap endpoint">
        <div class="flex items-center gap-2">
          <UInput :model-value="bootstrapEndpoint" readonly class="w-full font-mono text-xs" />
          <UButton
            label="Copy"
            icon="i-lucide-copy"
            color="neutral"
            variant="outline"
            class="shrink-0"
            @click="copyBootstrapEndpoint"
          />
        </div>
      </UFormField>

      <div v-if="profile === null" class="space-y-3">
        <UButton
          label="Create provisioning profile"
          icon="i-lucide-plus"
          color="primary"
          :loading="actionLoading"
          @click="createProfile"
        />
      </div>

      <template v-else>
        <UFormField label="Provision key">
          <div class="flex items-center gap-2">
            <UInput :model-value="profile.provision_key" readonly class="w-full font-mono text-xs" />
            <UButton
              label="Copy"
              icon="i-lucide-copy"
              color="neutral"
              variant="outline"
              class="shrink-0"
              @click="copyKey"
            />
          </div>
        </UFormField>

        <UFormField label="Provision secret">
          <div class="flex items-center gap-2">
            <UInput
              :model-value="secretDisplayValue"
              :type="secretVisible ? 'text' : 'password'"
              readonly
              class="w-full font-mono text-xs"
            >
              <template #trailing>
                <UButton
                  color="neutral"
                  variant="link"
                  size="sm"
                  :icon="secretVisible ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                  :aria-label="secretVisible ? 'Hide secret' : 'Show secret'"
                  :aria-pressed="secretVisible"
                  :disabled="provisionSecret === null"
                  @click="secretVisible = !secretVisible"
                />
              </template>
            </UInput>
            <UButton
              label="Copy"
              icon="i-lucide-copy"
              color="neutral"
              variant="outline"
              class="shrink-0"
              :disabled="provisionSecret === null"
              @click="copySecret"
            />
          </div>
        </UFormField>

        <div class="flex flex-wrap items-center gap-2">
          <UButton
            :label="profile.enabled ? 'Disable enrollment' : 'Enable enrollment'"
            :color="profile.enabled ? 'neutral' : 'primary'"
            :variant="profile.enabled ? 'subtle' : 'solid'"
            :loading="actionLoading"
            @click="setEnabled(!profile.enabled)"
          />
          <UButton
            label="Rotate secret"
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="subtle"
            :disabled="actionLoading"
            @click="rotateConfirmOpen = true"
          />
        </div>
      </template>
    </template>

    <UModal
      v-model:open="rotateConfirmOpen"
      title="Rotate provisioning secret?"
      description="The current secret will stop working for future device enrollments. Devices already provisioned keep their Access Tokens."
    >
      <template #body>
        <div class="flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="subtle"
            :disabled="actionLoading"
            @click="rotateConfirmOpen = false"
          />
          <UButton
            label="Rotate secret"
            color="primary"
            :loading="actionLoading"
            @click="rotateSecret"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
