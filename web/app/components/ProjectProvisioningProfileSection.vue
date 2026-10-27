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
    return { text: 'Not configured', color: 'neutral' as const, icon: 'i-lucide-circle-dashed' }
  }

  return profile.value.enabled
    ? { text: 'Enabled', color: 'success' as const, icon: 'i-lucide-circle-check' }
    : { text: 'Disabled', color: 'neutral' as const, icon: 'i-lucide-circle-pause' }
})

const quotaFull = computed(() => props.project.max_devices_quota !== null
  && props.project.device_count >= props.project.max_devices_quota)

const quotaLabel = computed(() => props.project.max_devices_quota === null
  ? 'Unlimited'
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
  secretVisible.value = false
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
  secretVisible.value = false
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
    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
      <div class="flex items-center gap-2">
        <span class="text-muted">Profile</span>
        <UBadge
          :color="profileBadge.color"
          :icon="profileBadge.icon"
          variant="subtle"
          size="md"
          class="text-sm"
        >
          {{ profileBadge.text }}
        </UBadge>
      </div>

      <div v-if="showQuota" class="flex items-center gap-2">
        <span class="text-muted">Capacity</span>
        <span class="font-medium text-highlighted">{{ quotaLabel }}</span>
        <UBadge v-if="quotaFull" color="error" variant="subtle" size="md" class="text-sm">
          Full
        </UBadge>
      </div>
    </div>

    <p v-if="showQuota && quotaFull" class="text-sm text-error">
      This project has reached its device limit. Contact a tenant administrator to increase the quota.
    </p>

    <div v-if="loading" class="space-y-3">
      <USkeleton class="h-10 w-full" />
      <USkeleton class="h-10 w-full" />
    </div>

    <template v-else>
      <div class="space-y-1.5">
        <span class="block text-sm text-muted">HTTPS bootstrap endpoint</span>
        <div class="flex items-center gap-1">
          <div class="min-w-0 flex-1 overflow-x-auto rounded-md border border-default bg-elevated/40 px-3 py-1.5 font-mono text-sm text-highlighted whitespace-nowrap">
            {{ bootstrapEndpoint }}
          </div>
          <UButton
            icon="i-lucide-copy"
            color="neutral"
            variant="ghost"
            aria-label="Copy bootstrap endpoint"
            @click="copyBootstrapEndpoint"
          />
        </div>
      </div>

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
        <div class="space-y-1.5">
          <span class="block text-sm text-muted">Provision key</span>
          <div class="flex items-center gap-1">
            <UInput
              :model-value="profile.provision_key"
              readonly
              class="min-w-0 flex-1 font-mono text-sm"
            />
            <UButton
              icon="i-lucide-copy"
              color="neutral"
              variant="ghost"
              aria-label="Copy provision key"
              @click="copyKey"
            />
          </div>
        </div>

        <div class="space-y-1.5">
          <span class="block text-sm text-muted">Provision secret</span>
          <div class="flex items-center gap-1">
            <UInput
              :model-value="secretDisplayValue"
              :type="secretVisible ? 'text' : 'password'"
              readonly
              class="min-w-0 flex-1 font-mono text-sm"
            />
            <UButton
              :icon="secretVisible ? 'i-lucide-eye-off' : 'i-lucide-eye'"
              color="neutral"
              variant="ghost"
              :aria-label="secretVisible ? 'Hide secret' : 'Show secret'"
              :disabled="provisionSecret === null"
              @click="secretVisible = !secretVisible"
            />
            <UButton
              icon="i-lucide-copy"
              color="neutral"
              variant="ghost"
              aria-label="Copy provision secret"
              :disabled="provisionSecret === null"
              @click="copySecret"
            />
          </div>
        </div>

        <!-- Rendered in the parent modal's footer, like the device Credentials modal actions. -->
        <Teleport defer to="#provisioning-profile-actions">
          <UButton
            :label="profile.enabled ? 'Disable' : 'Enable'"
            :color="profile.enabled ? 'error' : 'success'"
            variant="ghost"
            :loading="actionLoading"
            @click="setEnabled(!profile.enabled)"
          />
          <UButton
            label="Rotate"
            color="neutral"
            variant="ghost"
            :disabled="actionLoading"
            @click="rotateConfirmOpen = true"
          />
        </Teleport>
      </template>
    </template>

    <UModal
      v-model:open="rotateConfirmOpen"
      title="Rotate provisioning secret?"
    >
      <template #body>
        <p class="mb-4 text-sm text-muted">
          The current secret will stop working for future device enrollments. Devices already provisioned keep their Access Tokens.
        </p>
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
