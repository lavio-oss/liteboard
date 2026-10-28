<script setup lang="ts">
import type { NavigationMenuItem, TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useMqttCaCertificateDownload } from '~/composables/useMqttCaCertificateDownload'
import { useProvisioningCaCertificateDownload } from '~/composables/useProvisioningCaCertificateDownload'
import { useDevicePresenceStream } from '~/composables/useDevicePresenceStream'
import { useTenantContext } from '~/composables/useTenantContext'
import DevicePresenceStatus from '~/components/realtime/DevicePresenceStatus.vue'
import { canDeleteProjectData, canWriteProjectData } from '~/utils/projectPermissions'
import { applyDevicePresenceToDevice, mergeDevicePresenceEvents } from '~/utils/devicePresence'
import type {
  AdminTableRow,
  Device,
  DeviceApprovalStatus,
  DeviceInput,
  Project
} from '~/types/admin'
import type { DevicePresenceEventData } from '~/types/telemetry'

definePageMeta({
  middleware: 'auth'
})

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const toast = useToast()
const downloadMqttCaCertificate = useMqttCaCertificateDownload()
const downloadProvisioningCaCertificate = useProvisioningCaCertificateDownload()
const { activeTenantId, canManageActiveTenant, loadTenants } = useTenantContext()
const {
  createDevice,
  deleteDevice,
  approveDevice: submitDeviceApproval,
  rejectDevice: submitDeviceRejection,
  loadDeviceProfiles,
  loadDevices,
  loadTenantHierarchy
} = useAdmin()
await loadTenants()

const formOpen = ref(false)
const formLoading = ref(false)
const selectedProjectId = ref<string | null>(null)
const selectedEquipmentAssetId = ref<string | null>(null)
const selectedDeviceProfileVersionId = ref<string | null>(null)
const deleteOpen = ref(false)
const deleteLoading = ref(false)
const deletingDevice = ref<Device | null>(null)
const approvalActionDeviceId = ref<string | null>(null)
const rejectionOpen = ref(false)
const rejectionLoading = ref(false)
const rejectingDevice = ref<Device | null>(null)
const rejectionReason = ref('')
const credentialsOpen = ref(false)
const credentialsDevice = ref<Device | null>(null)
const mqttGuideOpen = ref(false)
const mqttGuideTab = ref('tls-ca')
// Same tab bar as the configuration screens (device profiles, alert rules, webhooks); the tab lives in the URL.
const route = useRoute()
const activeTab = computed(() => route.query.tab === 'provisioning' ? 'provisioning' : 'access-token')
const deviceTabs = computed<NavigationMenuItem[][]>(() => [[
  { label: 'Access token', icon: 'i-lucide-key-round', to: { query: {} }, active: activeTab.value === 'access-token' },
  { label: 'Provisioning', icon: 'i-lucide-radio-tower', to: { query: { tab: 'provisioning' } }, active: activeTab.value === 'provisioning' }
]])
const provisioningOpen = ref(false)
const projectDevicesOpen = ref(false)
const provisioningProjectId = ref<string | null>(null)
const mqttGuideTabs = [
  { label: 'TLS CA certificate', value: 'tls-ca', slot: 'tls-ca' },
  { label: 'Access token', value: 'access-token', slot: 'access-token' },
  { label: 'HTTPS provisioning', value: 'provisioning', slot: 'provisioning' }
]

const projectFilter = ref<string | null>(null)

function resetDeviceFilters(): void {
  projectFilter.value = null
}

const approvalStatusLabels: Record<DeviceApprovalStatus, string> = {
  pending: 'Waiting for approval',
  approved: 'Approved',
  rejected: 'Rejected'
}

const approvalStatusColors: Record<DeviceApprovalStatus, 'warning' | 'success' | 'error'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'error'
}

const { data: response, status, error, refresh } = await useAsyncData('tenant-devices', async () => {
  if (activeTenantId.value === null) {
    return {
      assets: [],
      deviceProfiles: [],
      devices: [],
      projects: []
    }
  }

  const tenantId = activeTenantId.value
  const hierarchy = await loadTenantHierarchy(tenantId)
  const [deviceLists, deviceProfileLists] = await Promise.all([
    Promise.all(hierarchy.projects.map(project => loadDevices(tenantId, project.id))),
    Promise.all(hierarchy.projects.map(project => loadDeviceProfiles(tenantId, project.id)))
  ])

  return {
    assets: hierarchy.assets,
    deviceProfiles: deviceProfileLists.flat(),
    devices: deviceLists.flat(),
    projects: hierarchy.projects
  }
}, { watch: [activeTenantId] })

const presenceEvents = ref<Record<string, DevicePresenceEventData>>({})

function acceptPresenceEvent(incoming: DevicePresenceEventData): void {
  const current = presenceEvents.value[incoming.device_id] ?? null
  const latest = mergeDevicePresenceEvents(current, incoming)

  if (latest !== current) {
    presenceEvents.value = { ...presenceEvents.value, [incoming.device_id]: latest }
  }
}

const presenceStream = useDevicePresenceStream(() => activeTenantId.value, {
  onPresence: acceptPresenceEvent,
  onLive: async () => {
    await refresh()
  }
})

watch(activeTenantId, () => {
  presenceEvents.value = {}
  response.value = { assets: [], deviceProfiles: [], devices: [], projects: [] }
}, { flush: 'sync' })

onMounted(() => presenceStream.start())

const assets = computed(() => response.value?.assets ?? [])
const devices = computed(() => (response.value?.devices ?? []).map((device) => {
  return applyDevicePresenceToDevice(device, presenceEvents.value[device.id] ?? null)
}))
const selectedProjectDeviceProfiles = computed(() => (response.value?.deviceProfiles ?? [])
  .filter(profile => profile.project_id === selectedProjectId.value))
const projects = computed(() => response.value?.projects ?? [])
const projectOptions = computed(() => [
  ...projects.value.map(project => ({ label: project.name, value: project.id }))
])
function canWriteProject(projectId: string): boolean {
  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The device project is not available.')
  }

  return canWriteProjectData(project.role, canManageActiveTenant.value)
}

function canDeleteProject(projectId: string): boolean {
  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The device project is not available.')
  }

  return canDeleteProjectData(project.role, canManageActiveTenant.value)
}

const writableProjects = computed(() => projects.value.filter(project => canWriteProject(project.id)))
const writableProjectOptions = computed(() => [
  ...writableProjects.value.map(project => ({ label: project.name, value: project.id }))
])
const canCreateDevice = computed(() => writableProjects.value.length > 0)
const canManageCredentials = computed(() => credentialsDevice.value !== null
  && credentialsDevice.value.approval_status === 'approved'
  && canWriteProject(credentialsDevice.value.project_id))
const projectFilterOptions = computed(() => [
  { label: 'All projects', value: null },
  ...projectOptions.value
])

// Read from the live list so the modal follows quota and device-count changes after a refresh.
const provisioningProject = computed(() => writableProjects.value
  .find(project => project.id === provisioningProjectId.value) ?? null)

function openProvisioning(project: Project): void {
  provisioningProjectId.value = project.id
  provisioningOpen.value = true
}

function openProjectDevices(project: Project): void {
  provisioningProjectId.value = project.id
  projectDevicesOpen.value = true
}

const sortableHeader = useSortableHeader()
const provisioningColumns: TableColumn<Project>[] = [
  {
    id: 'sequence',
    header: 'No.',
    cell: ({ row }) => row.index + 1
  },
  {
    id: 'name',
    accessorKey: 'name',
    header: 'Project',
    cell: ({ row }) => h('span', { class: 'font-medium text-highlighted' }, row.original.name)
  },
  {
    id: 'provisioning_enabled',
    accessorKey: 'provisioning_enabled',
    header: 'Enrollment',
    cell: ({ row }) => row.original.provisioning_enabled === null
      ? h('span', { class: 'text-muted' }, 'Not configured')
      : h(
          UBadge,
          { color: row.original.provisioning_enabled ? 'success' : 'neutral', variant: 'subtle' },
          () => row.original.provisioning_enabled ? 'Enabled' : 'Disabled'
        )
  },
  {
    id: 'device_count',
    accessorKey: 'device_count',
    header: sortableHeader('Devices')
  },
  {
    // Bootstrapped devices start pending and cannot connect until approved in the Access token tab.
    id: 'pending',
    header: 'Waiting for approval',
    cell: ({ row }) => {
      const pending = devices.value.filter(device => device.project_id === row.original.id && device.approval_status === 'pending').length

      return pending === 0 ? '—' : h(UBadge, { color: 'warning', variant: 'subtle' }, () => pending)
    }
  },
  {
    id: 'quota',
    // Unlimited sorts as the largest capacity.
    accessorFn: project => project.max_devices_quota ?? Number.POSITIVE_INFINITY,
    header: sortableHeader('Capacity'),
    cell: ({ row }) => row.original.max_devices_quota === null ? 'Unlimited' : `${row.original.device_count}/${row.original.max_devices_quota}`
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, {
        'icon': 'i-lucide-cpu',
        'color': 'neutral',
        'variant': 'ghost',
        'aria-label': `Devices of ${row.original.name}`,
        'onClick': () => openProjectDevices(row.original)
      }),
      h(UButton, {
        'icon': 'i-lucide-key-round',
        'color': 'neutral',
        'variant': 'ghost',
        'aria-label': `Provisioning key of ${row.original.name}`,
        'onClick': () => openProvisioning(row.original)
      })
    ])
  }
]

const equipmentAssetOptions = computed(() => [
  { label: 'No equipment asset', value: null },
  ...assets.value
    .filter(asset => asset.project_id === selectedProjectId.value && asset.asset_type === 'equipment')
    .map(asset => ({ label: `${asset.name} (${asset.code})`, value: asset.id }))
])

watch(selectedProjectId, (projectId) => {
  if (!selectedProjectDeviceProfiles.value.some(
    profile => profile.versions.some(version => version.id === selectedDeviceProfileVersionId.value)
  )) {
    selectedDeviceProfileVersionId.value = null
  }

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
  return projectFilter.value === null || device.project_id === projectFilter.value
}))

const rows = computed<AdminTableRow[]>(() => filteredDevices.value)

function projectNameFor(projectId: string | undefined): string {
  if (projectId === undefined) {
    throw new Error('The device project_id is missing.')
  }

  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The device project is not available.')
  }

  return project.name
}

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

function openCreate(): void {
  selectedProjectId.value = writableProjects.value[0]?.id ?? null
  selectedEquipmentAssetId.value = null
  selectedDeviceProfileVersionId.value = null
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

function openRejection(deviceId: string): void {
  const device = devices.value.find(item => item.id === deviceId)

  if (device === undefined) {
    throw new Error('The selected device is not available.')
  }

  rejectingDevice.value = device
  rejectionReason.value = ''
  rejectionOpen.value = true
}

async function approveSelectedDevice(deviceId: string): Promise<void> {
  if (activeTenantId.value === null) {
    throw new Error('An active tenant is required before approving a device.')
  }

  const device = devices.value.find(item => item.id === deviceId)

  if (device === undefined) {
    throw new Error('The selected device is not available.')
  }

  approvalActionDeviceId.value = deviceId

  try {
    await submitDeviceApproval(activeTenantId.value, device.project_id, device.id)
    await refresh()
    toast.add({
      title: 'Device approved',
      description: 'The device can now connect to LiteBoard over MQTT.',
      color: 'success'
    })
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    toast.add({
      title: 'Unable to approve device',
      description: requestError.message,
      color: 'error'
    })
  } finally {
    approvalActionDeviceId.value = null
  }
}

async function rejectSelectedDevice(): Promise<void> {
  if (activeTenantId.value === null || rejectingDevice.value === null) {
    throw new Error('An active tenant and device are required before rejecting a device.')
  }

  rejectionLoading.value = true

  try {
    await submitDeviceRejection(
      activeTenantId.value,
      rejectingDevice.value.project_id,
      rejectingDevice.value.id,
      rejectionReason.value
    )
    await refresh()
    rejectionOpen.value = false
    rejectingDevice.value = null
    rejectionReason.value = ''
    toast.add({
      title: 'Device rejected',
      description: 'The device will stop waiting for approval.',
      color: 'success'
    })
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    toast.add({
      title: 'Unable to reject device',
      description: requestError.message,
      color: 'error'
    })
  } finally {
    rejectionLoading.value = false
  }
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
    cell: ({ row }) => {
      const gatewayId = row.original.gateway_device_id

      return h('div', { class: 'flex flex-wrap items-center gap-1.5' }, [
        h('span', { class: 'font-medium text-highlighted' }, row.original.name),
        row.original.is_gateway ? h(UBadge, { color: 'info', variant: 'subtle', size: 'sm' }, () => 'Gateway') : null,
        gatewayId
          ? h('span', { class: 'text-xs text-muted' }, `via ${devices.value.find(device => device.id === gatewayId)?.name ?? 'gateway'}`)
          : null
      ])
    }
  },
  {
    id: 'code',
    accessorKey: 'code',
    header: 'Code'
  },
  {
    id: 'connection_state',
    accessorKey: 'connection_state',
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Connection',
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
      const { connection_state: connectionState, last_seen_at: lastSeenAt } = row.original

      if (connectionState === undefined || lastSeenAt === undefined) {
        throw new Error('The device presence fields are missing.')
      }

      return h(DevicePresenceStatus, {
        state: connectionState,
        lastSeenAt,
        compact: true
      })
    }
  },
  {
    id: 'approval_status',
    accessorKey: 'approval_status',
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Approval',
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
      const status = row.original.approval_status

      if (status === undefined) {
        throw new Error('The device approval state is missing.')
      }

      return h('div', { class: 'max-w-64' }, [
        h(
          UBadge,
          {
            class: 'capitalize',
            color: approvalStatusColors[status],
            variant: 'subtle'
          },
          () => approvalStatusLabels[status]
        )
      ])
    }
  },
  {
    id: 'status',
    accessorKey: 'status',
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Status',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
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
    id: 'equipment_asset_id',
    accessorKey: 'equipment_asset_id',
    header: 'Equipment',
    cell: ({ row }) => equipmentNameFor(row.original.equipment_asset_id)
  },
  {
    id: 'project_id',
    accessorKey: 'project_id',
    header: 'Project',
    cell: ({ row }) => projectNameFor(row.original.project_id)
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const projectId = row.original.project_id

      if (projectId === undefined) {
        throw new Error('The device project is missing.')
      }

      const actions: ReturnType<typeof h>[] = [
        h(UButton, {
          'icon': 'i-lucide-eye',
          'color': 'neutral',
          'variant': 'ghost',
          'to': `/devices/${projectId}/${row.original.id}`,
          'aria-label': `View ${row.original.name}`
        })
      ]

      if (canWriteProject(projectId)) {
        if (row.original.approval_status === 'pending') {
          actions.push(
            h(UButton, {
              'icon': 'i-lucide-circle-check',
              'color': 'success',
              'variant': 'ghost',
              'loading': approvalActionDeviceId.value === row.original.id,
              'aria-label': `Approve ${row.original.name}`,
              'onClick': () => { void approveSelectedDevice(row.original.id) }
            }),
            h(UButton, {
              'icon': 'i-lucide-ban',
              'color': 'error',
              'variant': 'ghost',
              'aria-label': `Reject ${row.original.name}`,
              'onClick': () => openRejection(row.original.id)
            })
          )
        }

        // Sub-devices connect only through their gateway, so they have no credentials to manage.
        if (row.original.approval_status === 'approved' && row.original.gateway_device_id === null) {
          actions.push(h(UButton, {
            'icon': 'i-lucide-key-round',
            'color': 'neutral',
            'variant': 'ghost',
            'aria-label': `Manage credentials for ${row.original.name}`,
            'onClick': () => openCredentials(row.original.id)
          }))
        }
      }

      if (canDeleteProject(projectId)) {
        actions.push(h(UButton, {
          'icon': 'i-lucide-trash-2',
          'color': 'error',
          'variant': 'ghost',
          'aria-label': `Delete ${row.original.name}`,
          'onClick': () => openDelete(row.original.id)
        }))
      }

      return h('div', { class: 'flex justify-end gap-1' }, actions)
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
            label="Setup tutorial"
            icon="i-lucide-book-open-check"
            color="neutral"
            variant="outline"
            @click="mqttGuideOpen = true"
          />
          <UButton
            v-if="activeTab === 'access-token' && canCreateDevice"
            label="Add device"
            icon="i-lucide-plus"
            @click="openCreate"
          />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <UNavigationMenu
          :items="deviceTabs"
          highlight
          class="-mx-1 flex-1"
        />
      </UDashboardToolbar>
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
          v-if="activeTab === 'access-token'"
          :columns="columns"
          :data="rows"
          :loading="status === 'pending'"
          search-placeholder="Search devices..."
          @reset="resetDeviceFilters"
        >
          <template #filters>
            <USelect
              v-model="projectFilter"
              :items="projectFilterOptions"
              class="min-w-40"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            />
          </template>
        </AdminTable>

        <AdminTable
          v-else
          :columns="provisioningColumns"
          :data="writableProjects"
          :loading="status === 'pending'"
          :show-status-filter="false"
          search-placeholder="Search projects..."
        />
      </div>

      <template v-if="provisioningProject !== null">
        <AdminProjectProvisioningModal
          v-model:open="provisioningOpen"
          :ui="{ content: 'max-w-2xl' }"
          :tenant-id="provisioningProject.tenant_id"
          :project="provisioningProject"
          :can-manage-quota="canManageActiveTenant"
          :show-quota="false"
          @updated="refresh"
        />
        <AdminProjectDevicesModal
          v-model:open="projectDevicesOpen"
          :tenant-id="provisioningProject.tenant_id"
          :project="provisioningProject"
          :devices="devices.filter(device => device.project_id === provisioningProject?.id)"
          :device-profiles="(response?.deviceProfiles ?? []).filter(profile => profile.project_id === provisioningProject?.id)"
          :can-manage-quota="canManageActiveTenant"
          :can-write="canWriteProject(provisioningProject.id)"
          :approving-device-id="approvalActionDeviceId"
          @updated="refresh"
          @approve="approveSelectedDevice"
          @reject="openRejection"
        />
      </template>

      <AdminResourceFormModal
        v-model:open="formOpen"
        v-model:project-id="selectedProjectId"
        v-model:equipment-asset-id="selectedEquipmentAssetId"
        v-model:device-profile-version-id="selectedDeviceProfileVersionId"
        resource-type="device"
        :project-options="writableProjectOptions"
        :equipment-asset-options="equipmentAssetOptions"
        :device-profiles="(response?.deviceProfiles ?? []).filter(profile => canWriteProject(profile.project_id))"
        :loading="formLoading"
        @submit-device="saveDevice"
      />

      <AdminDeviceCredentialsModal
        v-model:open="credentialsOpen"
        :ui="{ content: 'max-w-2xl' }"
        :tenant-id="activeTenantId"
        :device="credentialsDevice"
        :can-manage="canManageCredentials"
        @updated="refresh"
      />

      <UModal
        v-model:open="rejectionOpen"
        :ui="{ content: 'max-w-xl' }"
        title="Reject device"
      >
        <template #body>
          <div class="space-y-3">
            <p class="text-sm text-muted">
              The device will remain listed and cannot connect. This decision cannot be changed. Provide a reason: the device will receive it and stop waiting for approval.
            </p>
            <UTextarea
              v-model="rejectionReason"
              :rows="4"
              :maxlength="1000"
              placeholder="Reason for rejection"
            />
          </div>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="outline"
              :disabled="rejectionLoading"
              @click="rejectionOpen = false"
            />
            <UButton
              label="Reject device"
              color="error"
              :disabled="rejectionReason.length < 3"
              :loading="rejectionLoading"
              @click="rejectSelectedDevice"
            />
          </div>
        </template>
      </UModal>

      <UModal
        v-model:open="mqttGuideOpen"
        :ui="{ content: 'max-w-3xl' }"
        title="Connect a device over MQTT TLS"
      >
        <template #body>
          <UTabs
            v-model="mqttGuideTab"
            :items="mqttGuideTabs"
            variant="link"
            :ui="{
              list: 'w-full border-default p-0',
              trigger: 'flex-1 justify-center rounded-none py-3',
              content: 'pt-1'
            }"
            class="w-full"
          >
            <template #tls-ca>
              <div class="space-y-5 pt-4">
                <p class="text-sm text-muted">
                  HTTPS and MQTT use separate certificate authorities. Download each public CA for the matching connection; neither certificate is a device key or secret.
                </p>

                <ol class="list-inside list-decimal space-y-3 text-sm text-default">
                  <li>
                    Download both public root certificates using the separate buttons.
                    <div class="mt-2 flex flex-wrap gap-2">
                      <UButton
                        label="Download MQTT CA (.txt)"
                        color="neutral"
                        variant="outline"
                        @click="downloadMqttCaCertificate"
                      />
                      <UButton
                        label="Download HTTPS CA (.crt)"
                        color="neutral"
                        variant="outline"
                        @click="downloadProvisioningCaCertificate"
                      />
                    </div>
                  </li>
                  <li>
                    Install the MQTT CA in your MQTT client's trust store and the HTTPS CA in the device's HTTPS trust store. These roots are separate and must not be interchanged.
                  </li>
                  <li>
                    Keep certificate and hostname verification enabled for both connections. Use the HTTPS endpoint hostname for provisioning and a broker hostname listed in the MQTT certificate's SAN.
                  </li>
                </ol>

                <div class="grid gap-3 rounded-lg border border-default p-4 sm:grid-cols-2">
                  <div>
                    <p class="text-xs text-muted">
                      Protocol
                    </p>
                    <p class="mt-1 text-sm font-medium text-highlighted">
                      MQTT over TLS
                    </p>
                  </div>
                  <div>
                    <p class="text-xs text-muted">
                      Port
                    </p>
                    <p class="mt-1 text-sm font-medium text-highlighted">
                      Shown in the credentials modal
                    </p>
                  </div>
                  <div>
                    <p class="text-xs text-muted">
                      MQTT trusted CA
                    </p>
                    <p class="mt-1 text-sm font-medium text-highlighted">
                      Downloaded LiteBoard CA certificate
                    </p>
                  </div>
                  <div>
                    <p class="text-xs text-muted">
                      Verification
                    </p>
                    <p class="mt-1 text-sm font-medium text-highlighted">
                      Certificate chain and hostname
                    </p>
                  </div>
                </div>
              </div>
            </template>

            <template #access-token>
              <div class="space-y-5 pt-4">
                <p class="text-sm font-medium text-highlighted">
                  Manual enrollment checklist
                </p>
                <ol class="list-inside list-decimal space-y-3 text-sm text-default">
                  <li>Create the device with <strong>Add device</strong>, then open its credentials from the device row.</li>
                  <li>Install the MQTT CA from the first tab and keep certificate-chain and broker-hostname verification enabled.</li>
                  <li>Use the broker host, port, and TLS status shown in the credentials modal.</li>
                  <li>For quick experiments, turn on <strong>Allow connection without TLS</strong> for that device in the credentials modal. The token then travels unencrypted on the plaintext port, so do not use it for real devices.</li>
                  <li>Set the Access Token as both MQTT username and password, then publish telemetry to the topic shown in the credentials modal.</li>
                  <li>Return to the Devices list and confirm the device reports <strong>Online</strong>.</li>
                </ol>

                <div class="space-y-2 rounded-lg border border-default p-4">
                  <p class="text-sm font-medium text-highlighted">
                    MQTT credentials
                  </p>
                  <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                      <dt class="text-muted">
                        Username
                      </dt>
                      <dd class="mt-1 font-mono text-highlighted">
                        &lt;ACCESS_TOKEN&gt;
                      </dd>
                    </div>
                    <div>
                      <dt class="text-muted">
                        Password
                      </dt>
                      <dd class="mt-1 font-mono text-highlighted">
                        &lt;ACCESS_TOKEN&gt;
                      </dd>
                    </div>
                  </dl>
                  <div class="border-t border-default pt-3">
                    <p class="text-muted">
                      Telemetry topic
                    </p>
                    <code class="mt-1 block break-all font-mono text-xs text-highlighted">liteboard/&lt;PROJECT_ID&gt;/devices/&lt;DEVICE_ID&gt;/telemetry</code>
                  </div>
                </div>

                <p class="text-sm text-muted">
                  Keep the Access Token private. Rotate it from the device credentials modal if it is exposed.
                </p>
              </div>
            </template>

            <template #provisioning>
              <div class="space-y-5 pt-4">
                <p class="text-sm font-medium text-highlighted">
                  HTTPS provisioning checklist
                </p>
                <ol class="list-inside list-decimal space-y-3 text-sm text-default">
                  <li>Open the <strong>Provisioning</strong> tab, select the target Project and create a provisioning profile.</li>
                  <li>Copy the provision key and save the secret when it is shown. The secret is available only immediately after creation or rotation.</li>
                  <li>Enable enrollment after securely configuring the device with its profile key and secret.</li>
                  <li>Copy the HTTPS bootstrap endpoint from the Provisioning tab. Install the HTTPS CA from the first tab and verify the endpoint hostname and certificate before enrollment.</li>
                  <li>Bootstrap the device once. It is created as waiting for approval, and its firmware saves the returned Access Token and MQTT settings.</li>
                  <li>Approve the device from the Access token tab's device list. Until approval, the broker blocks the token; the firmware checks the decision and waits without repeating bootstrap.</li>
                  <li>After approval, the device connects to the returned MQTT host on the TLS endpoint with TLS and hostname verification enabled.</li>
                  <li>Use the returned Access Token as both MQTT username and password, publish to the returned telemetry topic, and confirm the device reports <strong>Online</strong>.</li>
                </ol>

                <div class="rounded-lg border border-default p-4">
                  <p class="text-sm font-medium text-highlighted">
                    Connection parameters
                  </p>
                  <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                      <dt class="text-muted">
                        Enrollment transport
                      </dt><dd class="mt-1 font-medium text-highlighted">
                        HTTPS with server certificate and hostname verification
                      </dd>
                    </div>
                    <div>
                      <dt class="text-muted">
                        MQTT transport
                      </dt><dd class="mt-1 font-medium text-highlighted">
                        MQTT over TLS
                      </dd>
                    </div>
                    <div>
                      <dt class="text-muted">
                        MQTT username and password
                      </dt><dd class="mt-1 font-mono text-highlighted">
                        Returned Access Token
                      </dd>
                    </div>
                    <div>
                      <dt class="text-muted">
                        Trusted roots
                      </dt><dd class="mt-1 font-medium text-highlighted">
                        Separate HTTPS and MQTT CA certificates
                      </dd>
                    </div>
                  </dl>
                </div>

                <p class="text-sm text-muted">
                  Disabling a profile stops new enrollments but does not revoke tokens already issued. Rotate the profile secret if it is exposed.
                </p>
              </div>
            </template>
          </UTabs>
        </template>
      </UModal>

      <ConfirmDeleteModal
        v-model:open="deleteOpen"
        :ui="{ content: 'max-w-xl' }"
        :title="`Delete ${deletingDevice?.name ?? 'device'}`"
        description="This action cannot be undone and will revoke its MQTT credentials."
        :loading="deleteLoading"
        @confirm="deleteSelectedDevice"
      />
    </template>
  </UDashboardPanel>
</template>
