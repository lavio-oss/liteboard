<script setup lang="ts">
import type { DropdownMenuItem, NavigationMenuItem, TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useMqttCaCertificateDownload } from '~/composables/useMqttCaCertificateDownload'
import { useProvisioningCaCertificateDownload } from '~/composables/useProvisioningCaCertificateDownload'
import { useDevicePresenceStream } from '~/composables/useDevicePresenceStream'
import { useTenantContext } from '~/composables/useTenantContext'
import DevicePresenceStatus from '~/components/realtime/DevicePresenceStatus.vue'
import DeviceProvisioningModal from '~/components/DeviceProvisioningModal.vue'
import { canDeleteProjectData, canWriteProjectData } from '~/utils/projectPermissions'
import { initialProvisioningProjectId } from '~/utils/provisioning'
import { applyDevicePresenceToDevice, mergeDevicePresenceEvents } from '~/utils/devicePresence'
import type {
  AdminTableRow,
  Device,
  DeviceApprovalStatus,
  DeviceInput
} from '~/types/admin'
import type { DevicePresenceEventData } from '~/types/telemetry'

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
  loadTenantHierarchy,
  updateDevice
} = useAdmin()
await loadTenants()

const formOpen = ref(false)
const formLoading = ref(false)
const selectedProjectId = ref<string | null>(null)
const selectedEquipmentAssetId = ref<string | null>(null)
const selectedDeviceProfileVersionId = ref<string | null>(null)
const editingDevice = ref<Device | null>(null)
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
const provisioningOpen = ref(false)
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

function openProvisioning(): void {
  provisioningProjectId.value = initialProvisioningProjectId(
    projectFilter.value,
    writableProjects.value.map(project => project.id)
  )
  provisioningOpen.value = true
}

const addDeviceItems = computed<DropdownMenuItem[][]>(() => [[
  ...(canCreateDevice.value
    ? [{ label: 'Create device with Access Token', icon: 'i-lucide-key-round', onSelect: openCreate }]
    : []),
  ...(writableProjects.value.length > 0
    ? [{ label: 'Set up multiple devices', icon: 'i-lucide-radio-tower', onSelect: openProvisioning }]
    : [])
]])

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
  editingDevice.value = null
  selectedProjectId.value = writableProjects.value[0]?.id ?? null
  selectedEquipmentAssetId.value = null
  selectedDeviceProfileVersionId.value = null
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
  selectedDeviceProfileVersionId.value = device.device_profile_version_id
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
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const projectId = row.original.project_id

      if (projectId === undefined) {
        throw new Error('The device project is missing.')
      }

      const actions: ReturnType<typeof h>[] = [
        h(UButton, {
          'icon': 'i-lucide-activity',
          'color': 'neutral',
          'variant': 'ghost',
          'to': `/devices/${projectId}/${row.original.id}`,
          'aria-label': `View telemetry for ${row.original.name}`
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

        if (row.original.approval_status === 'approved') {
          actions.push(h(UButton, {
            'icon': 'i-lucide-key-round',
            'color': 'neutral',
            'variant': 'ghost',
            'aria-label': `Manage credentials for ${row.original.name}`,
            'onClick': () => openCredentials(row.original.id)
          }))
        }

        actions.push(h(UButton, {
          'icon': 'i-lucide-pencil',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `Edit ${row.original.name}`,
          'onClick': () => openEdit(row.original.id)
        }))
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
          <UDropdownMenu
            v-if="addDeviceItems[0]?.length"
            :items="addDeviceItems"
            :content="{ align: 'end' }"
          >
            <UButton
              label="Add device"
              icon="i-lucide-plus"
              trailing-icon="i-lucide-chevron-down"
            />
          </UDropdownMenu>
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
        v-model:device-profile-version-id="selectedDeviceProfileVersionId"
        resource-type="device"
        :resource="editingDevice"
        :project-options="writableProjectOptions"
        :equipment-asset-options="equipmentAssetOptions"
        :device-profiles="selectedProjectDeviceProfiles"
        :loading="formLoading"
        @submit-device="saveDevice"
      />

      <AdminDeviceCredentialsModal
        v-model:open="credentialsOpen"
        :tenant-id="activeTenantId"
        :device="credentialsDevice"
        :can-manage="canManageCredentials"
        @updated="refresh"
      />

      <UModal
        v-model:open="rejectionOpen"
        title="Reject device"
        description="The device will remain listed and cannot connect. This decision cannot be changed."
      >
        <template #body>
          <div class="space-y-3">
            <p class="text-sm text-muted">
              Provide a reason. The device will receive this reason and stop waiting for approval.
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
        description="Set up TLS verification and MQTT device authentication."
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
                    Keep certificate and hostname verification enabled for both connections. Use the HTTPS endpoint hostname for provisioning and a broker hostname listed in the MQTT certificate's SAN on port <code class="font-mono text-highlighted">8883</code>.
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
                      8883
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
                  <li>Install the MQTT CA from the TLS tab and keep certificate-chain and broker-hostname verification enabled.</li>
                  <li>Use the broker host, port, and TLS status shown in the credentials modal. The MQTT TLS port is <code class="font-mono text-highlighted">8883</code>.</li>
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
                  <li>Open <strong>Provision devices</strong> for the target Project and create a provisioning profile.</li>
                  <li>Copy the provision key and save the secret when it is shown. The secret is available only immediately after creation or rotation.</li>
                  <li>Enable enrollment after securely configuring the device with its profile key and secret.</li>
                  <li>Copy the HTTPS bootstrap endpoint from the profile panel. Install the HTTPS CA from the TLS tab and verify the endpoint hostname and certificate before enrollment.</li>
                  <li>Bootstrap the device once. It is created as waiting for approval, and its firmware saves the returned Access Token and MQTT settings.</li>
                  <li>Approve the device from this list. Until approval, the broker blocks the token; the firmware checks the decision and waits without repeating bootstrap.</li>
                  <li>After approval, the device connects to the returned MQTT host on port <code class="font-mono text-highlighted">8883</code> with TLS and hostname verification enabled.</li>
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
                        MQTT over TLS, port 8883
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
        :title="`Delete ${deletingDevice?.name ?? 'device'}`"
        description="This action cannot be undone and will revoke its MQTT credentials."
        :loading="deleteLoading"
        @confirm="deleteSelectedDevice"
      />

      <DeviceProvisioningModal
        v-if="provisioningOpen"
        v-model:open="provisioningOpen"
        :initial-project-id="provisioningProjectId"
        :projects="writableProjects"
        :tenant-id="activeTenantId"
      />
    </template>
  </UDashboardPanel>
</template>
