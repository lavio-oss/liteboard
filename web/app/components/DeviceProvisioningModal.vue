<script setup lang="ts">
import type { Project } from '~/types/admin'
import ProjectProvisioningProfileSection from '~/components/ProjectProvisioningProfileSection.vue'

const props = defineProps<{
  initialProjectId: string | null
  projects: Project[]
  tenantId: string | null
}>()

const open = defineModel<boolean>('open', { required: true })
const selectedProjectId = ref<string | undefined>(undefined)
const requestUrl = useRequestURL()
const bootstrapEndpoint = computed(() => new URL(
  '/api/device-provisioning/bootstrap',
  requestUrl.origin
).toString())

const projectOptions = computed(() => props.projects.map(project => ({
  label: project.name,
  value: project.id
})))

const selectedProject = computed(() => selectedProjectId.value === undefined
  ? null
  : props.projects.find(project => project.id === selectedProjectId.value) ?? null)

watch(
  () => [open.value, props.tenantId, props.initialProjectId],
  ([isOpen]) => {
    selectedProjectId.value = isOpen ? props.initialProjectId ?? undefined : undefined
  },
  { immediate: true }
)

watch(
  () => props.projects.map(project => project.id),
  (projectIds) => {
    if (selectedProjectId.value !== undefined && !projectIds.includes(selectedProjectId.value)) {
      selectedProjectId.value = undefined
    }
  }
)

watch(() => props.tenantId, () => {
  selectedProjectId.value = undefined
})
</script>

<template>
  <UModal
    v-model:open="open"
    title="Provision devices"
    :ui="{ content: 'max-w-3xl' }"
  >
    <template #body>
      <div class="space-y-5">
        <UFormField label="Project">
          <USelect
            v-model="selectedProjectId"
            :items="projectOptions"
            placeholder="Select a project"
            class="w-full"
          />
        </UFormField>

        <UAlert
          v-if="projectOptions.length === 0"
          color="warning"
          variant="subtle"
          title="No project available"
          description="You need device-management access to a project before setting up provisioning."
        />

        <ProjectProvisioningProfileSection
          v-else-if="selectedProject !== null && tenantId !== null"
          :key="`${tenantId}-${selectedProject.id}`"
          :active="open"
          :bootstrap-endpoint="bootstrapEndpoint"
          :project="selectedProject"
          :show-quota="true"
          :tenant-id="tenantId"
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
