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
const { activeTenantId, canManageActiveTenant, loadTenants } = useTenantContext()
const {
  createDevice,
  deleteDevice,
  loadDevices,
  loadTenantHierarchy,
  updateDevice
} = useAdmin()
await loadTenants()

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

function resetDeviceFilters(): void {
  connectionStateFilter.value = 'all'
  projectFilter.value = null
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

const { data: response, status, error, refresh } = await useAsyncData('tenant-devices', async () => {
  if (activeTenantId.value === null) {
    return {
      assets: [],
      devices: [],
      projects: []
    }
  }

  const tenantId = activeTenantId.value
  const hierarchy = await loadTenantHierarchy(tenantId)
  const deviceLists = await Promise.all(
    hierarchy.projects.map(project => loadDevices(tenantId, project.id))
  )

  return {
    assets: hierarchy.assets,
    devices: deviceLists.flat(),
    projects: hierarchy.projects
  }
}, { watch: [activeTenantId] })

const assets = computed(() => response.value?.assets ?? [])
const devices = computed(() => response.value?.devices ?? [])
const projects = computed(() => response.value?.projects ?? [])
const projectOptions = computed(() => [
  ...projects.value.map(project => ({ label: project.name, value: project.id }))
])
const projectFilterOptions = computed(() => [
  { label: 'All projects', value: null },
  ...projectOptions.value
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

const filteredDevices = computed(() => devices.value.filter((device) => {
  const matchesConnection = connectionStateFilter.value === 'all'
    || device.connection_state === connectionStateFilter.value
  const matchesProject = projectFilter.value === null || device.project_id === projectFilter.value

  return matchesConnection && matchesProject
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

function openCreate(): void {
  editingDevice.value = null
  selectedProjectId.value = projects.value[0]?.id ?? null
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
  if (activeTenantId.value === null) {
    throw new Error('An active tenant is required before saving a device.')
  }

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
    if (editingDevice.value === null) {
      const createdDevice = await createDevice(activeTenantId.value, selectedProjectId.value, payload)
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
      await updateDevice(activeTenantId.value, editingDevice.value.project_id, editingDevice.value.id, payload)
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
  if (activeTenantId.value === null || deletingDevice.value === null) {
    throw new Error('An active tenant and device are required before deletion.')
  }

  deleteLoading.value = true

  try {
    await deleteDevice(activeTenantId.value, deletingDevice.value.project_id, deletingDevice.value.id)
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
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, {
        'icon': 'i-lucide-key-round',
        'color': 'neutral',
        'variant': 'ghost',
        'aria-label': `Manage credentials for ${row.original.name}`,
        'onClick': () => openCredentials(row.original.id)
      }),
      ...(canManageActiveTenant.value
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
            v-if="canManageActiveTenant"
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
        :tenant-id="activeTenantId"
        :device="credentialsDevice"
        :can-manage="canManageActiveTenant"
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
