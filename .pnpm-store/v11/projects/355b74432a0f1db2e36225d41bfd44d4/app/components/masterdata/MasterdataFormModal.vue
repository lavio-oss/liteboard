<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type {
  Factory,
  FactoryInput,
  MasterdataStatus,
  ProductionLine,
  ProductionLineInput,
  Project,
  ProjectInput
} from '~/types/masterdata'

type Resource = Project | Factory | ProductionLine
type ResourceType = 'project' | 'factory' | 'production-line'
type FormState = ProjectInput | FactoryInput | ProductionLineInput
type ParentOption = { label: string, value: number }

const props = withDefaults(defineProps<{
  open: boolean
  resourceType: ResourceType
  resource?: Resource | null
  parentId?: number | null
  parentOptions?: ParentOption[]
  loading?: boolean
}>(), {
  resource: null,
  parentId: null,
  parentOptions: () => [],
  loading: false
})

const emit = defineEmits<{
  'update:open': [value: boolean]
  'update:parent-id': [value: number | null]
  'submit-project': [value: ProjectInput]
  'submit-factory': [value: FactoryInput]
  'submit-production-line': [value: ProductionLineInput]
}>()

const projectSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  description: z.string().max(10000, 'Description is too long'),
  status: z.enum(['active', 'inactive'])
})

const factorySchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  code: z.string().min(1, 'Code is required').max(64, 'Code is too long'),
  location: z.string().max(255, 'Location is too long'),
  status: z.enum(['active', 'inactive'])
})

const productionLineSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  code: z.string().min(1, 'Code is required').max(64, 'Code is too long'),
  status: z.enum(['active', 'inactive'])
})

const schema = computed(() => {
  if (props.resourceType === 'project') {
    return projectSchema
  }

  if (props.resourceType === 'factory') {
    return factorySchema
  }

  return productionLineSchema
})

const state = reactive<{
  name: string
  description: string
  code: string
  location: string
  status: MasterdataStatus
}>({
  name: '',
  description: '',
  code: '',
  location: '',
  status: 'active'
})

const title = computed(() => {
  const action = props.resource === null ? 'Add' : 'Edit'

  if (props.resourceType === 'project') {
    return `${action} project`
  }

  if (props.resourceType === 'factory') {
    return `${action} factory`
  }

  return `${action} production line`
})

watch(
  () => [props.open, props.resource, props.resourceType],
  () => {
    const resource = props.resource

    state.name = resource === null || resource === undefined ? '' : resource.name
    state.description = resource !== null && resource !== undefined && 'description' in resource
      ? resource.description ?? ''
      : ''
    state.code = resource !== null && resource !== undefined && 'code' in resource
      ? resource.code
      : ''
    state.location = resource !== null && resource !== undefined && 'location' in resource
      ? resource.location ?? ''
      : ''
    state.status = resource === null || resource === undefined ? 'active' : resource.status
  },
  { immediate: true }
)

function onSubmit(event: FormSubmitEvent<FormState>): void {
  if (props.resourceType === 'project') {
    emit('submit-project', {
      name: event.data.name,
      description: 'description' in event.data ? event.data.description : '',
      status: event.data.status
    })

    return
  }

  if (props.resourceType === 'factory') {
    emit('submit-factory', {
      name: event.data.name,
      code: 'code' in event.data ? event.data.code : '',
      location: 'location' in event.data ? event.data.location : '',
      status: event.data.status
    })

    return
  }

  emit('submit-production-line', {
    name: event.data.name,
    code: 'code' in event.data ? event.data.code : '',
    status: event.data.status
  })
}
</script>

<template>
  <UModal
    :open="props.open"
    :title="title"
    @update:open="emit('update:open', $event)"
  >
    <template #body>
      <UForm
        :schema="schema"
        :state="state"
        :disabled="props.loading"
        class="space-y-4"
        @submit="onSubmit"
      >
        <UFormField
          name="name"
          label="Name"
          required
        >
          <UInput v-model="state.name" class="w-full" />
        </UFormField>

        <UFormField
          v-if="props.resourceType === 'project'"
          name="description"
          label="Description"
        >
          <UTextarea v-model="state.description" class="w-full" />
        </UFormField>

        <UFormField
          v-if="props.resourceType !== 'project'"
          :label="props.resourceType === 'factory' ? 'Project' : 'Factory'"
          required
        >
          <USelect
            :model-value="props.parentId ?? undefined"
            :items="props.parentOptions"
            class="w-full"
            :disabled="props.resource !== null"
            @update:model-value="emit('update:parent-id', $event)"
          />
        </UFormField>

        <UFormField
          v-if="props.resourceType !== 'project'"
          name="code"
          label="Code"
          required
        >
          <UInput v-model="state.code" class="w-full" />
        </UFormField>

        <UFormField
          v-if="props.resourceType === 'factory'"
          name="location"
          label="Location"
        >
          <UInput v-model="state.location" class="w-full" />
        </UFormField>

        <UFormField
          name="status"
          label="Status"
          required
        >
          <USelect
            v-model="state.status"
            :items="[
              { label: 'Active', value: 'active' },
              { label: 'Inactive', value: 'inactive' }
            ]"
            class="w-full"
          />
        </UFormField>

        <div class="flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="subtle"
            :disabled="props.loading"
            @click="emit('update:open', false)"
          />
          <UButton
            :label="props.resource === null ? 'Create' : 'Save changes'"
            color="primary"
            type="submit"
            :loading="props.loading"
          />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
