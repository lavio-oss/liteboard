<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { Tenant } from '~/types/auth'

const props = withDefaults(defineProps<{
  open: boolean
  tenant?: Tenant | null
  loading?: boolean
  errors?: Record<string, string[]>
}>(), {
  tenant: null,
  loading: false,
  errors: () => ({})
})

const emit = defineEmits<{
  'update:open': [value: boolean]
  'submit': [value: { name: string, code: string, status: 'active' | 'inactive' }]
}>()

const schema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  code: z.string().min(1, 'Code is required').max(64, 'Code is too long'),
  status: z.enum(['active', 'inactive'])
})

type FormState = z.output<typeof schema>

const state = reactive<FormState>({
  name: '',
  code: '',
  status: 'active'
})

const title = computed(() => props.tenant === null ? 'Add tenant' : 'Edit tenant')

watch(
  () => [props.open, props.tenant],
  () => {
    state.name = props.tenant === null || props.tenant === undefined ? '' : props.tenant.name
    state.code = props.tenant === null || props.tenant === undefined ? '' : props.tenant.code
    state.status = props.tenant === null || props.tenant === undefined ? 'active' : props.tenant.status
  },
  { immediate: true }
)

function onSubmit(event: FormSubmitEvent<FormState>): void {
  emit('submit', event.data)
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
          :error="props.errors.name?.[0]"
        >
          <UInput v-model="state.name" class="w-full" />
        </UFormField>

        <UFormField
          name="code"
          label="Code"
          required
          :error="props.errors.code?.[0]"
        >
          <UInput v-model="state.code" class="w-full" />
        </UFormField>

        <UFormField
          name="status"
          label="Status"
          required
          :error="props.errors.status?.[0]"
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
            :label="props.tenant === null ? 'Create' : 'Save changes'"
            color="primary"
            type="submit"
            :loading="props.loading"
          />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
