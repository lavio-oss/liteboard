<script setup lang="ts">
import { z } from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { webhookEventTypeSchema } from '~/types/alerting'
import type { WebhookEndpoint, WebhookEndpointInput } from '~/types/alerting'

type SelectOption = { label: string, value: string }

const props = withDefaults(defineProps<{
  endpoint?: WebhookEndpoint | null
  projectOptions: SelectOption[]
  loading?: boolean
}>(), {
  endpoint: null,
  loading: false
})

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  submit: [value: { projectId: string, input: WebhookEndpointInput }]
}>()

const schema = z.object({
  projectId: z.string({ error: 'Select a project.' }),
  name: z.string().trim().min(2, 'Enter a name of at least 2 characters.').max(255),
  // The API owns the destination policy (https, public addresses); this only checks the shape.
  url: z.string().trim().max(2048).url('Enter a valid URL.'),
  event_types: z.array(webhookEventTypeSchema).min(1, 'Select at least one event.'),
  enabled: z.boolean()
})

type EndpointFormState = z.infer<typeof schema>

const state = reactive<Partial<EndpointFormState>>({})
const eventItems = webhookEventTypeSchema.options.map(value => ({ label: value, value }))

watch(
  () => [open.value, props.endpoint],
  () => {
    const endpoint = props.endpoint

    state.projectId = endpoint?.project_id ?? props.projectOptions[0]?.value
    state.name = endpoint?.name ?? ''
    state.url = endpoint?.url ?? ''
    state.event_types = endpoint?.event_types ?? ['alert.opened', 'alert.cleared']
    state.enabled = endpoint?.enabled ?? true
  },
  { immediate: true }
)

function onSubmit(event: FormSubmitEvent<EndpointFormState>): void {
  const { projectId, ...input } = event.data

  emit('submit', { projectId, input })
}
</script>

<template>
  <UModal v-model:open="open" :title="props.endpoint === null ? 'Add webhook endpoint' : 'Edit webhook endpoint'">
    <template #body>
      <UForm
        :schema="schema"
        :state="state"
        :disabled="props.loading"
        class="space-y-4"
        @submit="onSubmit"
      >
        <UFormField name="projectId" label="Project" required>
          <USelect
            v-model="state.projectId"
            :items="props.projectOptions"
            class="w-full"
            :disabled="props.endpoint !== null"
          />
        </UFormField>
        <UFormField name="name" label="Name" required>
          <UInput v-model="state.name" class="w-full" placeholder="Enter endpoint name" />
        </UFormField>
        <UFormField
          name="url"
          label="URL"
          required
          help="Public https endpoints only; private networks and redirects are blocked."
        >
          <UInput v-model="state.url" class="w-full" placeholder="Enter endpoint URL" />
        </UFormField>
        <UFormField name="event_types" label="Events" required>
          <UCheckboxGroup v-model="state.event_types" :items="eventItems" />
        </UFormField>
        <UFormField name="enabled">
          <USwitch v-model="state.enabled" label="Enabled" />
        </UFormField>

        <div class="flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="subtle"
            :disabled="props.loading"
            @click="open = false"
          />
          <UButton :label="props.endpoint === null ? 'Create' : 'Save changes'" type="submit" :loading="props.loading" />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
