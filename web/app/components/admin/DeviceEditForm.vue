<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { Device, DeviceInput, DeviceProfile } from '~/types/admin'

type SelectOption = { label: string, value: string | null }

const props = withDefaults(defineProps<{
  device: Device
  projectName?: string
  equipmentAssetOptions: SelectOption[]
  deviceProfiles: DeviceProfile[]
  loading?: boolean
}>(), {
  projectName: undefined,
  loading: false
})

const emit = defineEmits<{
  submit: [value: DeviceInput]
}>()

const schema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  code: z.string().min(1, 'Code is required').max(64, 'Code is too long'),
  description: z.string().max(1000, 'Description is too long')
})

const state = reactive({ name: '', code: '', description: '' })
const isActive = ref(true)
const isGateway = ref(false)
const equipmentAssetId = ref<string | null>(null)
const profileId = ref<string | null>(null)
const profileVersionId = ref<string | null>(null)

const profileOptions = computed<SelectOption[]>(() => [
  { label: 'Default (no schema)', value: null },
  ...props.deviceProfiles.map(profile => ({ label: `${profile.name} (${profile.code})`, value: profile.id }))
])
const versionOptions = computed<SelectOption[]>(() => props.deviceProfiles
  .find(profile => profile.id === profileId.value)
  ?.versions
  .toSorted((a, b) => b.version - a.version)
  .map(version => ({ label: version.schema_code, value: version.id })) ?? [])

function selectProfile(id: string | null): void {
  profileId.value = id
  profileVersionId.value = versionOptions.value[0]?.value ?? null
}

function reset(): void {
  state.name = props.device.name
  state.code = props.device.code
  state.description = props.device.description ?? ''
  isActive.value = props.device.status === 'active'
  isGateway.value = props.device.is_gateway
  equipmentAssetId.value = props.device.equipment_asset_id
  profileVersionId.value = props.device.device_profile_version_id
  profileId.value = props.deviceProfiles.find(
    profile => profile.versions.some(version => version.id === props.device.device_profile_version_id)
  )?.id ?? null
}

watch(() => [props.device, props.deviceProfiles], reset, { immediate: true })

const dirty = computed(() => state.name !== props.device.name
  || state.code !== props.device.code
  || state.description !== (props.device.description ?? '')
  || isActive.value !== (props.device.status === 'active')
  || isGateway.value !== props.device.is_gateway
  || equipmentAssetId.value !== props.device.equipment_asset_id
  || profileVersionId.value !== props.device.device_profile_version_id)

function onSubmit(event: FormSubmitEvent<z.output<typeof schema>>): void {
  emit('submit', {
    name: event.data.name,
    code: event.data.code,
    description: event.data.description.trim() === '' ? null : event.data.description,
    equipment_asset_id: equipmentAssetId.value,
    device_profile_version_id: profileVersionId.value,
    status: isActive.value ? 'active' : 'inactive',
    is_gateway: isGateway.value
  })
}
</script>

<template>
  <UForm
    :schema="schema"
    :state="state"
    :disabled="props.loading"
    class="grid gap-4 pt-4 sm:grid-cols-2"
    @submit="onSubmit"
  >
    <UFormField
      name="name"
      label="Name"
      required
    >
      <UInput
        v-model="state.name"
        class="w-full"
        placeholder="Enter device name"
      />
    </UFormField>

    <UFormField
      name="code"
      label="Code"
      required
    >
      <UInput
        v-model="state.code"
        class="w-full"
        placeholder="Enter device code"
      />
    </UFormField>

    <UFormField label="Project">
      <UInput
        :model-value="props.projectName ?? '—'"
        class="w-full"
        disabled
      />
    </UFormField>

    <UFormField label="Equipment asset">
      <USelect
        v-model="equipmentAssetId"
        :items="props.equipmentAssetOptions"
        class="w-full"
      />
    </UFormField>

    <UFormField label="Device profile">
      <template #hint>
        <ULink to="/device-profiles" class="text-xs">
          Manage profiles
        </ULink>
      </template>
      <USelect
        :model-value="profileId"
        :items="profileOptions"
        class="w-full"
        @update:model-value="selectProfile"
      />
    </UFormField>

    <UFormField
      label="Profile version"
      :help="profileId !== null && versionOptions.length === 0 ? 'This profile has no versions yet.' : undefined"
    >
      <USelect
        v-model="profileVersionId"
        :items="versionOptions"
        class="w-full"
        placeholder="Select a version"
        :disabled="profileId === null || versionOptions.length === 0"
      />
    </UFormField>

    <UFormField
      name="description"
      label="Description"
      class="sm:col-span-2"
    >
      <UTextarea
        v-model="state.description"
        class="w-full"
        placeholder="Enter device description"
      />
    </UFormField>

    <UFormField name="status" label="Status" class="sm:col-span-2">
      <USwitch v-model="isActive" :label="isActive ? 'Active' : 'Inactive'" />
    </UFormField>

    <UFormField label="Gateway" class="sm:col-span-2">
      <USwitch
        v-model="isGateway"
        label="This device is a gateway"
        :disabled="props.device.gateway_device_id !== null"
      />
    </UFormField>

    <div class="flex justify-end gap-2 border-t border-default pt-4 sm:col-span-2">
      <UButton
        label="Reset"
        color="neutral"
        variant="subtle"
        :disabled="props.loading || !dirty"
        @click="reset"
      />
      <UButton
        label="Save changes"
        icon="i-lucide-save"
        type="submit"
        :loading="props.loading"
        :disabled="!dirty"
      />
    </div>
  </UForm>
</template>
