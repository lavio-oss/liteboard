<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { deviceProfileInputSchema } from '~/types/admin'
import type { DeviceProfile, DeviceProfileInput } from '~/types/admin'

type SelectOption = { label: string, value: string }

const props = withDefaults(defineProps<{
  resource?: DeviceProfile | null
  projectId?: string | null
  projectOptions?: SelectOption[]
  loading?: boolean
}>(), {
  resource: null,
  projectId: null,
  projectOptions: () => [],
  loading: false
})

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  'update:project-id': [value: string | null]
  'submit': [value: DeviceProfileInput]
}>()

const profileSchema = deviceProfileInputSchema.extend({
  description: z.string().max(1000, 'Description is too long')
})

type ProfileFormState = z.infer<typeof profileSchema>

const state = reactive<ProfileFormState>({
  code: '',
  name: '',
  description: ''
})

const title = computed(() => props.resource === null ? 'Add device profile' : 'Edit device profile')

watch(
  () => [open.value, props.resource],
  () => {
    const profile = props.resource

    state.code = profile === null ? '' : profile.code
    state.name = profile === null ? '' : profile.name
    state.description = profile === null ? '' : profile.description ?? ''
  },
  { immediate: true }
)

function onSubmit(event: FormSubmitEvent<ProfileFormState>): void {
  const data = profileSchema.parse(event.data)

  emit('submit', deviceProfileInputSchema.parse({
    ...data,
    description: data.description === '' ? null : data.description
  }))
}
</script>

<template>
  <UModal
    v-model:open="open"
    :title="title"
  >
    <template #body>
      <UForm
        :schema="profileSchema"
        :state="state"
        :disabled="props.loading"
        class="space-y-4"
        @submit="onSubmit"
      >
        <UFormField
          label="Project"
          required
        >
          <USelect
            :model-value="props.projectId ?? undefined"
            :items="props.projectOptions"
            class="w-full"
            :disabled="props.resource !== null"
            @update:model-value="emit('update:project-id', $event ?? null)"
          />
        </UFormField>

        <UFormField
          name="name"
          label="Name"
          required
        >
          <UInput v-model="state.name" class="w-full" placeholder="Enter profile name" />
        </UFormField>

        <UFormField
          name="code"
          label="Code"
          required
        >
          <UInput v-model="state.code" class="w-full" placeholder="Enter profile code" />
        </UFormField>

        <UFormField
          name="description"
          label="Description"
        >
          <UTextarea v-model="state.description" class="w-full" placeholder="Enter profile description" />
        </UFormField>

        <div class="flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="subtle"
            :disabled="props.loading"
            @click="open = false"
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
