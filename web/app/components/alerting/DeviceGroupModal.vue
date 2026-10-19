<script setup lang="ts">
import type { Device, DeviceProfile } from '~/types/admin'
import { versionProfiles } from '~/utils/alertWorkflowGraph'

export interface DeviceGroupChange {
  deviceId: string
  versionId: string | null
}

const props = defineProps<{
  devices: Device[]
  profiles: DeviceProfile[]
  // Set when editing the group of a profile version; null for a new or draft group.
  versionId: string | null
  // Version number of versionId; telemetry of added devices must carry it as schema_version.
  schemaVersion: number | null
  // Devices ticked on open: the version's members, or a draft group's devices.
  initialDeviceIds: string[]
  title: string
  canCreate: boolean
  loading: boolean
}>()

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  draft: [deviceIds: string[]]
  submit: [changes: DeviceGroupChange[]]
  create: []
}>()

const selectedIds = ref<string[]>([])
const query = ref('')

const profileByVersion = computed(() => versionProfiles(props.profiles))
const deviceItems = computed(() => props.devices
  .filter(device => `${device.name} ${device.code}`.toLowerCase().includes(query.value.toLowerCase()))
  .map(device => ({
    label: device.name,
    value: device.id,
    description: device.device_profile_version_id === null
      ? 'Unassigned'
      : profileByVersion.value.get(device.device_profile_version_id)?.name ?? 'Unknown profile'
  })))

watch(open, () => {
  selectedIds.value = [...props.initialDeviceIds]
  query.value = ''
})

const added = computed(() => selectedIds.value.filter(id => !props.initialDeviceIds.includes(id)))
const removed = computed(() => props.initialDeviceIds.filter(id => !selectedIds.value.includes(id)))
// Added devices that already use another version move away from it.
const moved = computed(() => props.devices.filter(device => added.value.includes(device.id) && device.device_profile_version_id !== null))
// A version group is only changed by adding or removing devices; a draft needs at least one.
const canApply = computed(() => props.versionId === null
  ? selectedIds.value.length > 0
  : added.value.length > 0 || removed.value.length > 0)

function apply(): void {
  if (props.versionId === null) {
    emit('draft', selectedIds.value)

    return
  }

  emit('submit', [
    ...added.value.map(deviceId => ({ deviceId, versionId: props.versionId })),
    ...removed.value.map(deviceId => ({ deviceId, versionId: null }))
  ])
}
</script>

<template>
  <UModal
    v-model:open="open"
    :title="props.title"
    :description="props.versionId === null
      ? 'Tick the devices of the group, then connect the group to a profile version on the canvas.'
      : `Tick the devices that use this profile version. Unticked devices are unassigned. Added devices must send schema_version ${props.schemaVersion}.`"
    :ui="{ content: 'max-w-2xl' }"
  >
    <template #body>
      <div class="space-y-4">
        <div class="flex gap-2">
          <UInput
            v-model="query"
            icon="i-lucide-search"
            placeholder="Search devices"
            class="flex-1"
          />
          <UButton
            v-if="props.canCreate"
            label="Add new"
            icon="i-lucide-plus"
            color="neutral"
            variant="outline"
            @click="emit('create')"
          />
        </div>

        <div class="max-h-96 overflow-y-auto rounded-md border border-default p-3">
          <UCheckboxGroup
            v-model="selectedIds"
            :items="deviceItems"
            value-key="value"
          />
          <p v-if="deviceItems.length === 0" class="text-center text-sm text-muted">
            No matching device.
          </p>
        </div>

        <div class="flex items-center justify-between gap-2">
          <p class="text-sm text-muted">
            <template v-if="props.versionId === null">
              {{ selectedIds.length }} selected
            </template>
            <template v-else>
              {{ added.length }} to add, {{ removed.length }} to unassign<template v-if="moved.length > 0">
                , {{ moved.length }} move from another version
              </template>
            </template>
          </p>
          <div class="flex gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="subtle"
              :disabled="props.loading"
              @click="open = false"
            />
            <UButton
              :label="props.versionId === null ? 'Save group' : 'Apply'"
              :loading="props.loading"
              :disabled="!canApply"
              @click="apply"
            />
          </div>
        </div>
      </div>
    </template>
  </UModal>
</template>
