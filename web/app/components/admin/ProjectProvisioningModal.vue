<script setup lang="ts">
import ProjectProvisioningProfileSection from '~/components/ProjectProvisioningProfileSection.vue'
import type { Project } from '~/types/admin'

withDefaults(defineProps<{
  tenantId: string | null
  project: Project | null
  canManageQuota: boolean
  // The devices page edits the quota in its Devices modal instead.
  showQuota?: boolean
}>(), {
  showQuota: true
})

const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ updated: [] }>()
const requestUrl = useRequestURL()

const bootstrapEndpoint = computed(() => new URL(
  '/api/device-provisioning/bootstrap',
  requestUrl.origin
).toString())
</script>

<template>
  <UModal
    v-model:open="open"
    :title="project ? `Device provisioning: ${project.name}` : 'Device provisioning'"
    :ui="{ content: 'max-w-3xl' }"
  >
    <template #body>
      <div v-if="project !== null && tenantId !== null" class="space-y-6">
        <template v-if="showQuota">
          <AdminProjectDeviceQuotaSection
            :tenant-id="tenantId"
            :project="project"
            :can-manage-quota="canManageQuota"
            @updated="emit('updated')"
          />

          <USeparator />
        </template>

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
      <div class="flex w-full items-center gap-2">
        <div id="provisioning-profile-actions" class="flex items-center gap-2" />
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
