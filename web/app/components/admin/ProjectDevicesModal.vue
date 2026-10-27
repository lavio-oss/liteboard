<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import DevicePresenceStatus from '~/components/realtime/DevicePresenceStatus.vue'
import type { Device, DeviceProfile, Project } from '~/types/admin'
import { versionProfiles } from '~/utils/alertWorkflowGraph'

const props = defineProps<{
  tenantId: string
  project: Project
  devices: Device[]
  deviceProfiles: DeviceProfile[]
  canManageQuota: boolean
  canWrite: boolean
  approvingDeviceId: string | null
}>()

const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{
  updated: []
  approve: [deviceId: string]
  reject: [deviceId: string]
}>()

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')

type Tab = 'approved' | 'waiting' | 'config'
const tab = ref<Tab>('approved')
watch(open, (isOpen) => {
  if (isOpen) {
    tab.value = 'approved'
  }
})

const approved = computed(() => props.devices.filter(device => device.approval_status === 'approved'))
// Rejected devices stay listed with their state so a decision is never silently hidden.
const waiting = computed(() => props.devices.filter(device => device.approval_status !== 'approved'))
const pendingCount = computed(() => waiting.value.filter(device => device.approval_status === 'pending').length)

const tabs = computed(() => [
  { label: `Approved (${approved.value.length})`, value: 'approved' },
  { label: `Waiting for approval (${pendingCount.value})`, value: 'waiting' },
  { label: 'Config', value: 'config' }
])

const profileByVersion = computed(() => versionProfiles(props.deviceProfiles))
const profileLabel = (versionId: string | null): string => {
  const profile = versionId === null ? undefined : profileByVersion.value.get(versionId)
  const version = profile?.versions.find(item => item.id === versionId)

  return profile === undefined || version === undefined ? '—' : `${profile.name} v${version.version}`
}

const deviceColumns = computed<TableColumn<Device>[]>(() => [
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
  ...(tab.value === 'approved'
    ? [{
      id: 'connection_state',
      header: 'Connection',
      cell: ({ row }) => h(DevicePresenceStatus, {
        state: row.original.connection_state,
        lastSeenAt: row.original.last_seen_at,
        compact: true
      })
    }, {
      id: 'status',
      header: 'Status',
      cell: ({ row }) => h(
        UBadge,
        { class: 'capitalize', color: row.original.status === 'active' ? 'success' : 'neutral', variant: 'subtle' },
        () => row.original.status
      )
    }] satisfies TableColumn<Device>[]
    : [{
      id: 'approval_status',
      header: 'Approval',
      cell: ({ row }) => h(
        UBadge,
        { class: 'capitalize', color: row.original.approval_status === 'pending' ? 'warning' : 'error', variant: 'subtle' },
        () => row.original.approval_status
      )
    }] satisfies TableColumn<Device>[]),
  {
    id: 'profile',
    header: 'Profile',
    cell: ({ row }) => profileLabel(row.original.device_profile_version_id)
  },
  ...(tab.value === 'waiting' && props.canWrite
    ? [{
      id: 'actions',
      header: 'Actions',
      cell: ({ row }) => row.original.approval_status !== 'pending'
        ? null
        : h('div', { class: 'flex justify-end gap-1' }, [
            h(UButton, {
              'icon': 'i-lucide-circle-check',
              'color': 'success',
              'variant': 'ghost',
              'loading': props.approvingDeviceId === row.original.id,
              'aria-label': `Approve ${row.original.name}`,
              'onClick': () => emit('approve', row.original.id)
            }),
            h(UButton, {
              'icon': 'i-lucide-ban',
              'color': 'error',
              'variant': 'ghost',
              'aria-label': `Reject ${row.original.name}`,
              'onClick': () => emit('reject', row.original.id)
            })
          ])
    }] satisfies TableColumn<Device>[]
    : [])
])
</script>

<template>
  <UModal
    v-model:open="open"
    :title="`Devices: ${project.name}`"
    :ui="{ content: 'max-w-2xl' }"
  >
    <template #body>
      <UTabs
        v-model="tab"
        :items="tabs"
        :content="false"
        variant="link"
        :ui="{ list: 'w-full border-default p-0', trigger: 'flex-1 justify-center rounded-none py-3' }"
        class="-mt-2 mb-4 w-full"
      />

      <AdminProjectDeviceQuotaSection
        v-if="tab === 'config'"
        :tenant-id="tenantId"
        :project="project"
        :can-manage-quota="canManageQuota"
        @updated="emit('updated')"
      />

      <UTable
        v-else
        :data="tab === 'approved' ? approved : waiting"
        :columns="deviceColumns"
        class="max-h-96 overflow-y-auto rounded-md border border-default"
        :empty="tab === 'approved' ? 'No approved device yet.' : 'No device is waiting for approval.'"
      />
    </template>

    <template #footer>
      <div class="flex w-full items-center gap-2">
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
