<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type {
  Asset,
  AssetInput,
  AssetType,
  Device,
  DeviceInput,
  DeviceProfile,
  ResourceStatus,
  Project,
  ProjectFormInput
} from '~/types/admin'
import { assetTypeSchema } from '~/types/admin'

type Resource = Project | Asset | Device
type ResourceType = 'project' | 'asset' | 'device'
type ProjectFormState = Omit<ProjectFormInput, 'tenant_id'> & { tenant_id: string | null }
type DeviceFormState = {
  name: string
  code: string
  description: string
  status: ResourceStatus
}
type FormState = ProjectFormState | Omit<AssetInput, 'parent_asset_id'> | DeviceFormState
type SelectOption = { label: string, value: string | null }

const props = withDefaults(defineProps<{
  resourceType: ResourceType
  resource?: Resource | null
  tenantId?: string | null
  tenantOptions?: SelectOption[]
  projectId?: string | null
  projectOptions?: SelectOption[]
  parentId?: string | null
  parentOptions?: SelectOption[]
  equipmentAssetId?: string | null
  equipmentAssetOptions?: SelectOption[]
  deviceProfiles?: DeviceProfile[]
  loading?: boolean
}>(), {
  resource: null,
  tenantId: null,
  tenantOptions: () => [],
  projectId: null,
  projectOptions: () => [],
  parentId: null,
  parentOptions: () => [],
  equipmentAssetId: null,
  equipmentAssetOptions: () => [],
  deviceProfiles: () => [],
  loading: false
})

const open = defineModel<boolean>('open', { required: true })
const deviceProfileVersionId = defineModel<string | null>('deviceProfileVersionId', { default: null })
const deviceProfileId = ref<string | null>(null)

const emit = defineEmits<{
  'update:tenant-id': [value: string | null]
  'update:project-id': [value: string | null]
  'update:parent-id': [value: string | null]
  'update:equipment-asset-id': [value: string | null]
  'submit-project': [value: ProjectFormInput]
  'submit-asset': [value: AssetInput]
  'submit-device': [value: DeviceInput]
}>()

const projectSchema = z.object({
  tenant_id: z.string().uuid('Tenant is required'),
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  description: z.string().max(10000, 'Description is too long'),
  status: z.enum(['active', 'inactive'])
})

const assetSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  code: z.string().min(1, 'Code is required').max(64, 'Code is too long'),
  asset_type: assetTypeSchema,
  location: z.string().max(255, 'Location is too long'),
  status: z.enum(['active', 'inactive'])
})

const deviceSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  code: z.string().min(1, 'Code is required').max(64, 'Code is too long'),
  description: z.string().max(1000, 'Description is too long'),
  status: z.enum(['active', 'inactive'])
})

const assetTypeOptions: Array<{ label: string, value: AssetType }> = [
  { label: 'Site', value: 'site' },
  { label: 'Factory', value: 'factory' },
  { label: 'Area', value: 'area' },
  { label: 'Production Line', value: 'production_line' },
  { label: 'Station', value: 'station' },
  { label: 'Equipment', value: 'equipment' }
]

// The project's "default" profile is the null option; profiles may span projects, so they name theirs.
const deviceProfileOptions = computed<SelectOption[]>(() => {
  const spansProjects = new Set(props.deviceProfiles.map(profile => profile.project_id)).size > 1
  const projectName = (projectId: string) => props.projectOptions.find(option => option.value === projectId)?.label

  return [
    { label: 'Default (no schema)', value: null },
    ...props.deviceProfiles
      .filter(profile => profile.code !== 'default')
      .map(profile => ({
        label: spansProjects ? `${profile.name} (${profile.code}) · ${projectName(profile.project_id)}` : `${profile.name} (${profile.code})`,
        value: profile.id
      }))
  ]
})

const deviceProfileVersionOptions = computed<SelectOption[]>(() => props.deviceProfiles
  .find(profile => profile.id === deviceProfileId.value)
  ?.versions
  .toSorted((a, b) => b.version - a.version)
  .map(version => ({ label: version.schema_code, value: version.id })) ?? [])

// A profile belongs to one project, so choosing one also fixes the device's project (step 2).
const selectedDeviceProfile = computed(() => props.deviceProfiles.find(profile => profile.id === deviceProfileId.value) ?? null)

function selectDeviceProfile(profileId: string | null): void {
  deviceProfileId.value = profileId
  deviceProfileVersionId.value = deviceProfileVersionOptions.value[0]?.value ?? null

  if (selectedDeviceProfile.value !== null) {
    emit('update:project-id', selectedDeviceProfile.value.project_id)
  }
}

const isTwoColumnLayout = computed(() => props.resourceType === 'asset')
// Device creation is two steps: identity and profile first, then where the device belongs.
const deviceStep = ref<1 | 2>(1)
const isGateway = ref(false)

const schema = computed(() => {
  if (props.resourceType === 'project') {
    return projectSchema
  }

  if (props.resourceType === 'device') {
    return deviceSchema
  }

  return assetSchema
})

const state = reactive<{
  tenant_id: string | null
  name: string
  description: string
  code: string
  asset_type: AssetType
  location: string
  status: ResourceStatus
}>({
  tenant_id: null,
  name: '',
  description: '',
  code: '',
  asset_type: 'site',
  location: '',
  status: 'active'
})

const isActive = computed({
  get: () => state.status === 'active',
  set: (val: boolean) => {
    state.status = val ? 'active' : 'inactive'
  }
})

const title = computed(() => {
  const action = props.resource === null ? 'Add' : 'Edit'

  return `${action} ${props.resourceType}`
})

const deviceStepTabs = [
  { label: 'Device & profile', value: 1 },
  { label: 'Project & asset', value: 2 }
]

watch(
  () => [open.value, props.resource, props.resourceType, props.tenantId],
  () => {
    const resource = props.resource

    state.tenant_id = props.tenantId
    state.name = resource === null || resource === undefined ? '' : resource.name
    state.description = resource !== null && resource !== undefined && 'description' in resource
      ? resource.description ?? ''
      : ''
    state.code = resource !== null && resource !== undefined && 'code' in resource
      ? resource.code
      : ''
    state.asset_type = resource !== null && resource !== undefined && 'asset_type' in resource
      ? resource.asset_type
      : 'site'
    state.location = resource !== null && resource !== undefined && 'location' in resource
      ? resource.location ?? ''
      : ''
    state.status = resource === null || resource === undefined ? 'active' : resource.status
    deviceStep.value = 1
    isGateway.value = false
    deviceProfileId.value = props.deviceProfiles.find(
      profile => profile.versions.some(version => version.id === deviceProfileVersionId.value)
    )?.id ?? null
  },
  { immediate: true }
)

function onSubmit(event: FormSubmitEvent<FormState>): void {
  if (props.resourceType === 'project') {
    const data = projectSchema.parse(event.data)

    emit('submit-project', data)

    return
  }

  if (props.resourceType === 'device') {
    if (deviceStep.value === 1) {
      deviceStep.value = 2

      return
    }

    const data = deviceSchema.parse(event.data)
    const normalizedDescription = data.description.trim() === '' ? null : data.description

    emit('submit-device', {
      name: data.name,
      code: data.code,
      description: normalizedDescription,
      equipment_asset_id: props.equipmentAssetId,
      device_profile_version_id: deviceProfileVersionId.value,
      status: data.status,
      is_gateway: isGateway.value
    })

    return
  }

  const data = assetSchema.parse(event.data)

  emit('submit-asset', {
    ...data,
    parent_asset_id: props.parentId
  })
}
</script>

<template>
  <UModal
    v-model:open="open"
    :title="title"
    :ui="{ content: isTwoColumnLayout ? 'max-w-3xl' : props.resourceType === 'device' ? 'max-w-xl' : undefined }"
  >
    <template #body>
      <UTabs
        v-if="props.resourceType === 'device'"
        v-model="deviceStep"
        :items="deviceStepTabs"
        :content="false"
        variant="link"
        :ui="{ list: 'w-full border-default p-0', trigger: 'flex-1 justify-center rounded-none py-3' }"
        class="-mt-2 mb-4 w-full"
      />

      <!-- Step 1 fields carry the validation, so a failed submit from step 2 goes back to show the errors. -->
      <UForm
        :schema="schema"
        :state="state"
        :disabled="props.loading"
        :class="isTwoColumnLayout ? 'grid grid-cols-1 gap-4 sm:grid-cols-2' : 'space-y-4'"
        @submit="onSubmit"
        @error="deviceStep = 1"
      >
        <UFormField
          v-if="props.resourceType !== 'device' || deviceStep === 1"
          name="name"
          label="Name"
          required
        >
          <UInput v-model="state.name" class="w-full" placeholder="Enter name" />
        </UFormField>

        <UFormField
          v-if="props.resourceType === 'project'"
          name="tenant_id"
          label="Tenant"
          required
        >
          <USelect
            v-model="state.tenant_id"
            :items="props.tenantOptions"
            class="w-full"
            :disabled="props.resource !== null"
            @update:model-value="emit('update:tenant-id', $event)"
          />
        </UFormField>

        <UFormField
          v-if="props.resourceType === 'project'"
          name="description"
          label="Description"
        >
          <UTextarea v-model="state.description" class="w-full" placeholder="Enter description" />
        </UFormField>

        <template v-else-if="props.resourceType === 'asset'">
          <UFormField
            label="Project"
            required
          >
            <USelect
              :model-value="props.projectId"
              :items="props.projectOptions"
              class="w-full"
              :disabled="props.resource !== null"
              @update:model-value="emit('update:project-id', $event)"
            />
          </UFormField>

          <UFormField
            label="Parent asset"
          >
            <USelect
              :model-value="props.parentId"
              :items="props.parentOptions"
              class="w-full"
              @update:model-value="emit('update:parent-id', $event)"
            />
          </UFormField>

          <UFormField
            name="asset_type"
            label="Asset type"
            required
          >
            <USelect
              v-model="state.asset_type"
              :items="assetTypeOptions"
              class="w-full"
            />
          </UFormField>

          <UFormField
            name="code"
            label="Code"
            required
          >
            <UInput v-model="state.code" class="w-full" placeholder="Enter asset code" />
          </UFormField>

          <UFormField
            name="location"
            label="Location"
          >
            <UInput v-model="state.location" class="w-full" placeholder="Enter asset location" />
          </UFormField>
        </template>

        <template v-else-if="props.resourceType === 'device' && deviceStep === 1">
          <UFormField
            name="code"
            label="Code"
            required
          >
            <UInput v-model="state.code" class="w-full" placeholder="Enter device code" />
          </UFormField>

          <UFormField label="Device profile">
            <template #hint>
              <ULink to="/device-profiles" class="text-xs">
                Manage profiles
              </ULink>
            </template>
            <USelect
              :model-value="deviceProfileId"
              :items="deviceProfileOptions"
              class="w-full"
              @update:model-value="selectDeviceProfile"
            />
          </UFormField>

          <UFormField
            v-if="deviceProfileId !== null"
            label="Profile version"
            :help="deviceProfileVersionOptions.length === 0 ? 'This profile has no versions yet.' : undefined"
          >
            <USelect
              v-model="deviceProfileVersionId"
              :items="deviceProfileVersionOptions"
              class="w-full"
              placeholder="Select a version"
              :disabled="deviceProfileVersionOptions.length === 0"
            />
          </UFormField>

          <UFormField
            name="description"
            label="Description"
          >
            <UTextarea v-model="state.description" class="w-full" placeholder="Enter device description" />
          </UFormField>

          <UFormField label="Gateway">
            <USwitch v-model="isGateway" label="This device is a gateway" />
          </UFormField>
        </template>

        <template v-else-if="props.resourceType === 'device'">
          <UFormField
            label="Project"
            required
            :help="selectedDeviceProfile !== null ? 'Set by the selected device profile.' : undefined"
          >
            <USelect
              :model-value="props.projectId"
              :items="props.projectOptions"
              class="w-full"
              :disabled="props.resource !== null || selectedDeviceProfile !== null"
              @update:model-value="emit('update:project-id', $event)"
            />
          </UFormField>

          <UFormField
            label="Equipment asset"
          >
            <USelect
              :model-value="props.equipmentAssetId"
              :items="props.equipmentAssetOptions"
              class="w-full"
              @update:model-value="emit('update:equipment-asset-id', $event)"
            />
          </UFormField>
        </template>

        <UFormField
          v-if="props.resourceType !== 'device' || deviceStep === 1"
          name="status"
          label="Status"
          :class="isTwoColumnLayout ? 'sm:col-span-2' : undefined"
        >
          <USwitch
            v-model="isActive"
            :label="isActive ? 'Active' : 'Inactive'"
          />
        </UFormField>

        <div :class="isTwoColumnLayout ? 'flex justify-end gap-2 sm:col-span-2' : 'flex justify-end gap-2'">
          <UButton
            v-if="props.resourceType === 'device' && deviceStep === 2"
            label="Back"
            color="neutral"
            variant="subtle"
            :disabled="props.loading"
            @click="deviceStep = 1"
          />
          <UButton
            v-else
            label="Cancel"
            color="neutral"
            variant="subtle"
            :disabled="props.loading"
            @click="open = false"
          />
          <UButton
            :label="props.resourceType === 'device' && deviceStep === 1 ? 'Next' : props.resource === null ? 'Create' : 'Save changes'"
            color="primary"
            type="submit"
            :loading="props.loading"
          />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
