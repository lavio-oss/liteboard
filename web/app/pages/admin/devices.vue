<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useTenantContext } from '~/composables/useTenantContext'
import type {
  AdminTableRow,
  Device,
  DeviceConnectionState,
  DeviceInput
} from '~/types/admin'

definePageMeta({
  middleware: 'auth'
})

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const toast = useToast()
const { isPlatformAdmin, loadTenants, tenants } = useTenantContext()
await loadTenants()

const tenantIds = computed(() => tenants.value.map(tenant => tenant.id))
const manageableTenantIds = computed(() => new Set(
  tenants.value
    .filter(tenant => isPlatformAdmin.value || tenant.role === 'owner' || tenant.role === 'admin')
    .map(tenant => tenant.id)
))
const canCreate = computed(() => isPlatformAdmin.value || manageableTenantIds.value.size > 0)
const addRequested = useState('admin:add-requested', () => false)
const {
  createDevice,
  deleteDevice,
  loadDevices,
  loadTenantHierarchy,
  updateDevice
} = useAdmin()

const formOpen = ref(false)
const formLoading = ref(false)
const selectedProjectId = ref<string | null>(null)
const selectedEquipmentAssetId = ref<string | null>(null)
const editingDevice = ref<Device | null>(null)
const deleteOpen = ref(false)
const deleteLoading = ref(false)
const deletingDevice = ref<Device | null>(null)
const credentialsOpen = ref(false)
const credentialsDevice = ref<Device | null>(null)

const connectionStateFilter = ref<DeviceConnectionState | 'all'>('all')
const projectFilter = ref<string | null>(null)
const tenantFilter = ref<string | null>(null)

function resetDeviceFilters(): void {
  connectionStateFilter.value = 'all'
  projectFilter.value = null
  tenantFilter.value = null
}

const connectionStateLabels: Record<DeviceConnectionState, string> = {
  online: 'Online',
  offline: 'Offline',
  unstable: 'Unstable'
}

const connectionStateColors: Record<DeviceConnectionState, 'success' | 'neutral' | 'warning'> = {
  online: 'success',
  offline: 'neutral',
  unstable: 'warning'
}

const connectionStateOptions: Array<{ label: string, value: DeviceConnectionState | 'all' }> = [
  { label: 'All connection states', value: 'all' },
  ...Object.entries(connectionStateLabels).map(([value, label]) => ({
    label,
    value: value as DeviceConnectionState
  }))
]

const { data: response, status, error, refresh } = await useAsyncData('admin-devices', async () => {
  const tenantData = await Promise.all(
    tenants.value.map(async (tenant) => {
      const hierarchy = await loadTenantHierarchy(tenant.id)
      const deviceLists = await Promise.all(
        hierarchy.projects.map(project => loadDevices(tenant.id, project.id))
      )

      return {
        assets: hierarchy.assets,
        devices: deviceLists.flat(),
        projects: hierarchy.projects
      }
    })
  )

  return {
    assets: tenantData.flatMap(item => item.assets),
    devices: tenantData.flatMap(item => item.devices),
    projects: tenantData.flatMap(item => item.projects)
  }
}, { watch: [tenantIds] })

const assets = computed(() => response.value?.assets ?? [])
const devices = computed(() => response.value?.devices ?? [])
const projects = computed(() => response.value?.projects ?? [])
const manageableProjects = computed(() => projects.value.filter(
  project => manageableTenantIds.value.has(project.tenant_id)
))
const projectOptions = computed(() => [
  ...manageableProjects.value.map(project => ({ label: project.name, value: project.id }))
])
const projectFilterOptions = computed(() => [
  { label: 'All projects', value: null },
  ...projects.value.map(project => ({ label: project.name, value: project.id }))
])
const tenantFilterOptions = computed(() => [
  { label: 'All tenants', value: null },
  ...tenants.value.map(tenant => ({ label: tenant.name, value: tenant.id }))
])

const equipmentAssetOptions = computed(() => [
  { label: 'No equipment asset', value: null },
  ...assets.value
    .filter(asset => asset.project_id === selectedProjectId.value && asset.asset_type === 'equipment')
    .map(asset => ({ label: `${asset.name} (${asset.code})`, value: asset.id }))
])

watch(selectedProjectId, (projectId) => {
  if (selectedEquipmentAssetId.value === null) {
    return
  }

  const matchesProject = assets.value.some(
    asset => asset.id === selectedEquipmentAssetId.value
      && asset.project_id === projectId
      && asset.asset_type === 'equipment'
  )

  if (!matchesProject) {
    selectedEquipmentAssetId.value = null
  }
})

function tenantIdForProject(projectId: string): string {
  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The device project is not available.')
  }

  return project.tenant_id
}

const filteredDevices = computed(() => devices.value.filter((device) => {
  const matchesConnection = connectionStateFilter.value === 'all'
    || device.connection_state === connectionStateFilter.value
  const matchesProject = projectFilter.value === null || device.project_id === projectFilter.value
  const matchesTenant = tenantFilter.value === null
    || tenantIdForProject(device.project_id) === tenantFilter.value

  return matchesConnection && matchesProject && matchesTenant
}))

const rows = computed<AdminTableRow[]>(() => filteredDevices.value)

function equipmentNameFor(equipmentAssetId: string | null | undefined): string {
  if (equipmentAssetId === null) {
    return '—'
  }

  if (equipmentAssetId === undefined) {
    throw new Error('The device equipment_asset_id is missing.')
  }

  const equipment = assets.value.find(item => item.id === equipmentAssetId)

  if (equipment === undefined) {
    throw new Error('The device equipment asset is not available.')
  }

  return equipment.name
}

function connectionStateLabelFor(connectionState: DeviceConnectionState | undefined): string {
  if (connectionState === undefined) {
    throw new Error('The device connection_state is missing.')
  }

  return connectionStateLabels[connectionState]
}

const credentialsTenantId = computed(() => {
  if (credentialsDevice.value === null) {
    return null
  }

  return tenantIdForProject(credentialsDevice.value.project_id)
})

const canManageCredentialsDevice = computed(() => {
  if (credentialsTenantId.value === null) {
    return false
  }

  return manageableTenantIds.value.has(credentialsTenantId.value)
})

function openCreate(): void {
  editingDevice.value = null
  selectedProjectId.value = manageableProjects.value[0]?.id ?? null
  selectedEquipmentAssetId.value = null
  formOpen.value = true
}

function openEdit(deviceId: string): void {
  const device = devices.value.find(item => item.id === deviceId)

  if (device === undefined) {
    throw new Error('The selected device is not available.')
  }

  editingDevice.value = device
  selectedProjectId.value = device.project_id
  selectedEquipmentAssetId.value = device.equipment_asset_id
  formOpen.value = true
}

function openDelete(deviceId: string): void {
  const device = devices.value.find(item => item.id === deviceId)

  if (device === undefined) {
    throw new Error('The selected device is not available.')
  }

  deletingDevice.value = device
  deleteOpen.value = true
}

function openCredentials(deviceId: string): void {
  const device = devices.value.find(item => item.id === deviceId)

  if (device === undefined) {
    throw new Error('The selected device is not available.')
  }

  credentialsDevice.value = device
  credentialsOpen.value = true
}

async function saveDevice(payload: DeviceInput): Promise<void> {
  if (selectedProjectId.value === null) {
    toast.add({
      title: 'Project is required',
      description: 'Select a project before saving the device.',
      color: 'error'
    })

    return
  }

  formLoading.value = true

  try {
    const projectTenantId = tenantIdForProject(selectedProjectId.value)

    if (editingDevice.value === null) {
      const createdDevice = await createDevice(projectTenantId, selectedProjectId.value, payload)
      await refresh()
      formOpen.value = false
      credentialsDevice.value = createdDevice
      credentialsOpen.value = true
      toast.add({
        title: 'Device created',
        description: 'The device and its access token have been generated.',
        color: 'success'
      })
    } else {
      await updateDevice(projectTenantId, editingDevice.value.project_id, editingDevice.value.id, payload)
      await refresh()
      formOpen.value = false
      toast.add({
        title: 'Device updated',
        description: 'The device has been saved.',
        color: 'success'
      })
    }
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    toast.add({
      title: 'Unable to save device',
      description: requestError.message,
      color: 'error'
    })
  } finally {
    formLoading.value = false
  }
}

async function deleteSelectedDevice(): Promise<void> {
  if (deletingDevice.value === null) {
    throw new Error('A device is required before deletion.')
  }

  deleteLoading.value = true

  try {
    await deleteDevice(
      tenantIdForProject(deletingDevice.value.project_id),
      deletingDevice.value.project_id,
      deletingDevice.value.id
    )
    await refresh()
    deleteOpen.value = false
    toast.add({
      title: 'Device deleted',
      description: 'The device and its credentials have been deleted.',
      color: 'success'
    })
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    toast.add({
      title: 'Unable to delete device',
      description: requestError.message,
      color: 'error'
    })
  } finally {
    deleteLoading.value = false
  }
}

watch(addRequested, (requested) => {
  if (requested) {
    addRequested.value = false
    openCreate()
  }
})

const columns: TableColumn<AdminTableRow>[] = [
  {
    id: 'sequence',
    header: 'No.',
    cell: ({ row }) => row.index + 1
  },
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
    id: 'equipment_asset_id',
    accessorKey: 'equipment_asset_id',
    header: 'Equipment',
    cell: ({ row }) => equipmentNameFor(row.original.equipment_asset_id)
  },
  {
    id: 'connection_state',
    accessorKey: 'connection_state',
    header: 'Connection',
    cell: ({ row }) => {
      const connectionState = row.original.connection_state

      if (connectionState === undefined) {
        throw new Error('The device connection_state is missing.')
      }

      return h(
        UBadge,
        { class: 'capitalize', color: connectionStateColors[connectionState], variant: 'subtle' },
        () => connectionStateLabelFor(connectionState)
      )
    }
  },
  {
    id: 'last_seen_at',
    accessorKey: 'last_seen_at',
    header: 'Last seen',
    cell: ({ row }) => {
      if (row.original.last_seen_at === undefined) {
        throw new Error('The device last_seen_at value is missing.')
      }

      return row.original.last_seen_at === null
        ? '—'
        : formatDateTime(row.original.last_seen_at)
    }
  },
  {
    id: 'status',
    accessorKey: 'status',
    header: 'Status',
    cell: ({ row }) => h(
      UBadge,
      {
        class: 'capitalize',
        color: row.original.status === 'active' ? 'success' : 'neutral',
        variant: 'subtle'
      },
      () => row.original.status
    )
  },
  {
    id: 'actions',
    header: '',
    cell: ({ row }) => {
      if (row.original.project_id === undefined) {
        throw new Error('The device project is missing.')
      }

      const canManage = manageableTenantIds.value.has(tenantIdForProject(row.original.project_id))

      return h('div', { class: 'flex justify-end gap-1' }, [
        h(UButton, {
          'icon': 'i-lucide-key-round',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `Manage credentials for ${row.original.name}`,
          'onClick': () => openCredentials(row.original.id)
        }),
        ...(canManage
          ? [
              h(UButton, {
                'icon': 'i-lucide-pencil',
                'color': 'neutral',
                'variant': 'ghost',
                'aria-label': `Edit ${row.original.name}`,
                'onClick': () => openEdit(row.original.id)
              }),
              h(UButton, {
                'icon': 'i-lucide-trash-2',
                'color': 'error',
                'variant': 'ghost',
                'aria-label': `Delete ${row.original.name}`,
                'onClick': () => openDelete(row.original.id)
              })
            ]
          : [])
      ])
    }
  }
]

const errorMessage = computed(() => {
  if (!(error.value instanceof ApiError)) {
    return 'The devices could not be loaded.'
  }

  return error.value.message
})
</script>

<template>
  <UDashboardPanel id="devices" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Devices">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="canCreate"
            label="Add device"
            icon="i-lucide-plus"
            @click="openCreate"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="status === 'error'"
          color="error"
          variant="subtle"
          title="Unable to load devices"
          :description="errorMessage"
        />

        <AdminTable
          :columns="columns"
          :data="rows"
          :loading="status === 'pending'"
          search-placeholder="Search devices..."
          @reset="resetDeviceFilters"
        >
          <template #filters>
            <USelect
              v-model="connectionStateFilter"
              :items="connectionStateOptions"
              class="min-w-44"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            />
            <USelect
              v-model="projectFilter"
              :items="projectFilterOptions"
              class="min-w-40"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            />
            <USelect
              v-model="tenantFilter"
              :items="tenantFilterOptions"
              class="min-w-40"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            />
          </template>
        </AdminTable>
      </div>

      <AdminResourceFormModal
        v-model:open="formOpen"
        v-model:project-id="selectedProjectId"
        v-model:equipment-asset-id="selectedEquipmentAssetId"
        resource-type="device"
        :resource="editingDevice"
        :project-options="projectOptions"
        :equipment-asset-options="equipmentAssetOptions"
        :loading="formLoading"
        @submit-device="saveDevice"
      />

      <AdminDeviceCredentialsModal
        v-model:open="credentialsOpen"
        :tenant-id="credentialsTenantId"
        :device="credentialsDevice"
        :can-manage="canManageCredentialsDevice"
        @updated="refresh"
      />

      <ConfirmDeleteModal
        v-model:open="deleteOpen"
        :title="`Delete ${deletingDevice?.name ?? 'device'}`"
        description="This action cannot be undone and will revoke its MQTT credentials."
        :loading="deleteLoading"
        @confirm="deleteSelectedDevice"
      />
    </template>
  </UDashboardPanel>
</template>
