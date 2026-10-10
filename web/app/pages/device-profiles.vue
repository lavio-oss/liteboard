<script setup lang="ts">
import type { NavigationMenuItem, TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useTenantContext } from '~/composables/useTenantContext'
import { canDeleteProjectData, canWriteProjectData } from '~/utils/projectPermissions'
import type {
  AdminTableRow,
  Device,
  DeviceProfile,
  DeviceProfileInput,
  DeviceProfileVersion,
  DeviceProfileVersionInput,
  Project
} from '~/types/admin'

definePageMeta({
  middleware: 'auth'
})

const deviceLinks = [[{
  label: 'Devices',
  icon: 'i-lucide-router',
  to: '/devices',
  exact: true
}, {
  label: 'Device Profiles',
  icon: 'i-lucide-file-cog',
  to: '/device-profiles'
}]] satisfies NavigationMenuItem[][]

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const toast = useToast()
const { activeTenantId, canManageActiveTenant, loadTenants } = useTenantContext()
const {
  createDeviceProfile,
  createDeviceProfileVersion,
  deleteDeviceProfile,
  deleteDeviceProfileVersion,
  loadDeviceProfiles,
  loadDevices,
  loadProjects,
  updateDeviceProfile
} = useAdmin()
await loadTenants()

const { data: response, status, error, refresh } = await useAsyncData(
  'tenant-device-profiles',
  async () => {
    const tenantId = activeTenantId.value

    if (tenantId === null) {
      return {
        devices: [] as Device[],
        profiles: [] as DeviceProfile[],
        projects: [] as Project[]
      }
    }

    const projects = await loadProjects(tenantId)
    const [profileLists, deviceLists] = await Promise.all([
      Promise.all(projects.map(project => loadDeviceProfiles(tenantId, project.id))),
      Promise.all(projects.map(project => loadDevices(tenantId, project.id)))
    ])

    return {
      devices: deviceLists.flat(),
      profiles: profileLists.flat(),
      projects
    }
  },
  { watch: [activeTenantId] }
)

const profiles = computed(() => response.value?.profiles ?? [])
const devices = computed(() => response.value?.devices ?? [])
const connectedDeviceNamesByProfileId = computed(() => {
  const profileIdByVersionId = new Map(profiles.value.flatMap(profile => (
    profile.versions.map(version => [version.id, profile.id] as const)
  )))
  const namesByProfileId = new Map<string, string[]>()

  for (const device of devices.value) {
    const profileId = device.device_profile_version_id === null
      ? undefined
      : profileIdByVersionId.get(device.device_profile_version_id)

    if (profileId !== undefined) {
      namesByProfileId.set(profileId, [...(namesByProfileId.get(profileId) ?? []), device.name])
    }
  }

  return namesByProfileId
})
const projects = computed(() => response.value?.projects ?? [])
const writableProjects = computed(() => projects.value.filter(project => (
  canWriteProjectData(project.role, canManageActiveTenant.value)
)))
const writableProjectOptions = computed(() => writableProjects.value.map(project => ({
  label: project.name,
  value: project.id
})))

const projectFilter = ref<string | null>(null)
const projectFilterOptions = computed(() => [
  { label: 'All projects', value: null },
  ...projects.value.map(project => ({ label: project.name, value: project.id }))
])

const filteredProfiles = computed(() => profiles.value.filter(profile => (
  projectFilter.value === null || profile.project_id === projectFilter.value
)))
const rows = computed<AdminTableRow[]>(() => filteredProfiles.value)

const formOpen = ref(false)
const formLoading = ref(false)
const formProjectId = ref<string | null>(null)
const editingProfile = ref<DeviceProfile | null>(null)
const deleteOpen = ref(false)
const deleteLoading = ref(false)
const deletingProfile = ref<DeviceProfile | null>(null)
const versionsOpen = ref(false)
const versionsLoading = ref(false)
const versionsView = ref<'versions' | 'form'>('versions')
const versionsProfile = ref<DeviceProfile | null>(null)
const deleteVersionOpen = ref(false)
const deleteVersionLoading = ref(false)
const deletingVersion = ref<DeviceProfileVersion | null>(null)

watch(activeTenantId, () => {
  projectFilter.value = null
  formOpen.value = false
  editingProfile.value = null
  deleteOpen.value = false
  deletingProfile.value = null
  versionsOpen.value = false
  versionsView.value = 'versions'
  versionsProfile.value = null
  deleteVersionOpen.value = false
  deletingVersion.value = null
})

function projectFor(projectId: string): Project {
  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The profile project is not available.')
  }

  return project
}

function canWriteInProject(projectId: string): boolean {
  return canWriteProjectData(projectFor(projectId).role, canManageActiveTenant.value)
}

function canDeleteInProject(projectId: string): boolean {
  return canDeleteProjectData(projectFor(projectId).role, canManageActiveTenant.value)
}

function resetProfileFilters(): void {
  projectFilter.value = null
}

function openCreate(): void {
  const project = writableProjects.value[0]

  if (project === undefined) {
    return
  }

  editingProfile.value = null
  formProjectId.value = project.id
  formOpen.value = true
}

function openEdit(profileId: string): void {
  const profile = profiles.value.find(item => item.id === profileId)

  if (profile === undefined) {
    throw new Error('The selected profile is not available.')
  }

  editingProfile.value = profile
  formProjectId.value = profile.project_id
  formOpen.value = true
}

function openDelete(profileId: string): void {
  const profile = profiles.value.find(item => item.id === profileId)

  if (profile === undefined) {
    throw new Error('The selected profile is not available.')
  }

  deletingProfile.value = profile
  deleteOpen.value = true
}

function openVersions(profileId: string): void {
  const profile = profiles.value.find(item => item.id === profileId)

  if (profile === undefined) {
    throw new Error('The selected profile is not available.')
  }

  versionsProfile.value = profile
  versionsView.value = 'versions'
  versionsOpen.value = true
}

async function saveProfile(payload: DeviceProfileInput): Promise<void> {
  const projectId = formProjectId.value

  if (projectId === null) {
    toast.add({
      title: 'Project is required',
      description: 'Select a project before saving the profile.',
      color: 'error'
    })

    return
  }

  const project = projectFor(projectId)
  formLoading.value = true

  try {
    if (editingProfile.value === null) {
      await createDeviceProfile(project.tenant_id, project.id, payload)
    } else {
      await updateDeviceProfile(project.tenant_id, project.id, editingProfile.value.id, payload)
    }

    await refresh()
    formOpen.value = false
    toast.add({
      title: editingProfile.value === null ? 'Profile created' : 'Profile updated',
      description: 'The profile has been saved.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to save profile',
      description: error.message,
      color: 'error'
    })
  } finally {
    formLoading.value = false
  }
}

async function deleteSelectedProfile(): Promise<void> {
  const profile = deletingProfile.value

  if (profile === null) {
    throw new Error('A profile is required before deletion.')
  }

  const project = projectFor(profile.project_id)
  deleteLoading.value = true

  try {
    await deleteDeviceProfile(project.tenant_id, project.id, profile.id)
    await refresh()
    deleteOpen.value = false
    toast.add({
      title: 'Profile deleted',
      description: 'The profile was deleted.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to delete profile',
      description: error.message,
      color: 'error'
    })
  } finally {
    deleteLoading.value = false
  }
}

async function createSelectedVersion(payload: DeviceProfileVersionInput): Promise<void> {
  const profile = versionsProfile.value

  if (profile === null) {
    throw new Error('A device profile is required before creating a version.')
  }

  const project = projectFor(profile.project_id)
  versionsLoading.value = true

  try {
    const version = await createDeviceProfileVersion(
      project.tenant_id,
      project.id,
      profile.id,
      payload
    )

    if (activeTenantId.value !== project.tenant_id) {
      return
    }

    await refresh()

    const refreshedProfile = profiles.value.find(item => item.id === profile.id)

    if (refreshedProfile === undefined) {
      throw new Error('The saved profile is no longer available.')
    }

    versionsProfile.value = refreshedProfile
    versionsView.value = 'versions'
    toast.add({
      title: 'Version created',
      description: `Created ${refreshedProfile.code} version ${version.version}.`,
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to create version',
      description: error.message,
      color: 'error'
    })
  } finally {
    versionsLoading.value = false
  }
}

function promptDeleteVersion(version: DeviceProfileVersion): void {
  deletingVersion.value = version
  deleteVersionOpen.value = true
}

async function deleteSelectedVersion(): Promise<void> {
  const profile = versionsProfile.value
  const version = deletingVersion.value

  if (profile === null || version === null) {
    throw new Error('A device profile and version are required before deletion.')
  }

  const project = projectFor(profile.project_id)
  deleteVersionLoading.value = true

  try {
    await deleteDeviceProfileVersion(
      project.tenant_id,
      project.id,
      profile.id,
      version.id
    )

    if (activeTenantId.value !== project.tenant_id) {
      return
    }

    await refresh()

    const refreshedProfile = profiles.value.find(item => item.id === profile.id)
    if (refreshedProfile !== undefined) {
      versionsProfile.value = refreshedProfile
    }

    versionsView.value = 'versions'
    deleteVersionOpen.value = false
    deletingVersion.value = null

    toast.add({
      title: 'Version deleted',
      description: `Deleted version ${version.schema_code}.`,
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to delete version',
      description: error.message,
      color: 'error'
    })
  } finally {
    deleteVersionLoading.value = false
  }
}

const columns: TableColumn<AdminTableRow>[] = [
  {
    id: 'name',
    accessorKey: 'name',
    header: 'Name',
    cell: ({ row }) => h('span', { class: 'font-medium text-highlighted' }, row.original.name)
  },
  {
    id: 'code',
    accessorKey: 'code',
    header: 'Code'
  },
  {
    id: 'project_id',
    accessorKey: 'project_id',
    header: 'Project',
    cell: ({ row }) => {
      const projectId = row.original.project_id

      if (projectId === undefined) {
        throw new Error('The profile project is missing.')
      }

      return projectFor(projectId).name
    }
  },
  {
    id: 'devices',
    header: 'Connected devices',
    cell: ({ row }) => {
      const deviceNames = connectedDeviceNamesByProfileId.value.get(row.original.id) ?? []

      if (deviceNames.length === 0) {
        return '—'
      }

      return h('div', { class: 'flex flex-wrap gap-1' }, deviceNames.map(name => h(
        UBadge,
        { color: 'neutral', variant: 'subtle' },
        () => name
      )))
    }
  },
  {
    id: 'updated_at',
    accessorKey: 'updated_at',
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Updated',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
    cell: ({ row }) => {
      if (row.original.updated_at === undefined) {
        throw new Error('The profile updated_at value is missing.')
      }

      return row.original.updated_at === null ? '—' : formatDateTime(row.original.updated_at)
    }
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const projectId = row.original.project_id

      if (projectId === undefined) {
        throw new Error('The profile project is missing.')
      }

      const actions: ReturnType<typeof h>[] = []

      if (canWriteInProject(projectId)) {
        actions.push(h(UButton, {
          'icon': 'i-lucide-pencil',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `Edit ${row.original.name}`,
          'onClick': () => openEdit(row.original.id)
        }))
      }

      actions.push(h(UButton, {
        'icon': 'i-lucide-settings',
        'color': 'neutral',
        'variant': 'ghost',
        'aria-label': `Manage versions for ${row.original.name}`,
        'onClick': () => openVersions(row.original.id)
      }))

      if (canDeleteInProject(projectId)) {
        actions.push(h(UButton, {
          'icon': 'i-lucide-trash-2',
          'color': 'error',
          'variant': 'ghost',
          'aria-label': `Delete ${row.original.name}`,
          'onClick': () => openDelete(row.original.id)
        }))
      }

      return actions.length > 0
        ? h('div', { class: 'flex justify-end gap-1' }, actions)
        : null
    }
  }
]

const errorMessage = computed(() => {
  if (error.value instanceof ApiError) {
    return error.value.message
  }

  return 'The device profiles could not be loaded.'
})
</script>

<template>
  <UDashboardPanel id="device-profiles" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Device Profiles">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="writableProjects.length > 0"
            label="Add profile"
            icon="i-lucide-plus"
            @click="openCreate"
          />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <UNavigationMenu :items="deviceLinks" highlight class="-mx-1 flex-1" />
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="status === 'error'"
          color="error"
          variant="subtle"
          title="Unable to load device profiles"
          :description="errorMessage"
        />

        <UAlert
          v-if="status !== 'pending' && projects.length === 0"
          color="warning"
          variant="subtle"
          title="No project assigned"
          description="Your account is not currently assigned to any project. Contact an administrator to request access."
        />

        <AdminTable
          v-else
          :columns="columns"
          :data="rows"
          :loading="status === 'pending'"
          :show-status-filter="false"
          search-placeholder="Search profiles..."
          @reset="resetProfileFilters"
        >
          <template #search-trailing>
            <USelect
              v-if="projects.length > 1"
              v-model="projectFilter"
              :items="projectFilterOptions"
              class="min-w-44"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            />
          </template>
        </AdminTable>
      </div>

      <AdminDeviceProfileFormModal
        v-model:open="formOpen"
        v-model:project-id="formProjectId"
        :resource="editingProfile"
        :project-options="writableProjectOptions"
        :loading="formLoading"
        @submit="saveProfile"
      />

      <ConfirmDeleteModal
        v-model:open="deleteOpen"
        :title="`Delete ${deletingProfile?.name ?? 'profile'}`"
        description="This action cannot be undone. A profile assigned to a device cannot be deleted."
        :loading="deleteLoading"
        @confirm="deleteSelectedProfile"
      />

      <ConfirmDeleteModal
        v-model:open="deleteVersionOpen"
        :title="`Delete version ${deletingVersion?.schema_code ?? ''}`"
        description="This action cannot be undone. Devices assigned to this version will be unlinked."
        :loading="deleteVersionLoading"
        @confirm="deleteSelectedVersion"
      />

      <AdminDeviceProfileVersionsDrawer
        v-model:open="versionsOpen"
        v-model:view="versionsView"
        :profile="versionsProfile"
        :can-write="versionsProfile !== null && canWriteInProject(versionsProfile.project_id)"
        :can-delete="versionsProfile !== null && canDeleteInProject(versionsProfile.project_id)"
        :creating="versionsLoading"
        :deleting="deleteVersionLoading"
        @create="createSelectedVersion"
        @delete="promptDeleteVersion"
      />
    </template>
  </UDashboardPanel>
</template>
