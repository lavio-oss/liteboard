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
  versionId: string
  // Version number of versionId; telemetry of added devices must carry it as schema_version.
  schemaVersion: number | null
  // Devices ticked on open: the version's members.
  initialDeviceIds: string[]
  title: string
  canCreate: boolean
  loading: boolean
}>()

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
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

// Immediate: the page mounts this modal already open the first time a version is picked.
watch(open, () => {
  selectedIds.value = [...props.initialDeviceIds]
  query.value = ''
}, { immediate: true })

const added = computed(() => selectedIds.value.filter(id => !props.initialDeviceIds.includes(id)))
const removed = computed(() => props.initialDeviceIds.filter(id => !selectedIds.value.includes(id)))
// Added devices that already use another version move away from it.
const moved = computed(() => props.devices.filter(device => added.value.includes(device.id) && device.device_profile_version_id !== null))
const canApply = computed(() => added.value.length > 0 || removed.value.length > 0)

// Unticked devices get null, which the backend turns into the project's Default profile.
function apply(): void {
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
    :ui="{ content: 'max-w-2xl' }"
  >
    <template #body>
      <div class="space-y-4">
        <p class="text-sm text-muted">
          Tick the devices that use this profile version. Unticked devices move back to the Default profile. Added devices must send schema_version {{ props.schemaVersion }}.
        </p>

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
            {{ added.length }} to add, {{ removed.length }} back to Default<template v-if="moved.length > 0">
              , {{ moved.length }} move from another version
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
              label="Apply"
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
