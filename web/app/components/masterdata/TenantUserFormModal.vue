<script setup lang="ts">
import type { FormSubmitEvent } from '@nuxt/ui'
import * as z from 'zod'
import type { TenantUser, TenantUserCreateInput, TenantUserUpdateInput } from '~/types/auth'

const props = withDefaults(defineProps<{
  user: TenantUser | null
  loading?: boolean
}>(), {
  loading: false
})

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  create: [payload: TenantUserCreateInput]
  update: [payload: TenantUserUpdateInput]
}>()

type FormState = {
  name: string
  email: string
  password: string
  password_confirmation: string
}

const state = reactive<FormState>({
  name: '',
  email: '',
  password: '',
  password_confirmation: ''
})
const editSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  email: z.string().email('Enter a valid email address'),
  password: z.string().refine(value => value === '' || value.length >= 8, 'Password must be at least 8 characters'),
  password_confirmation: z.string()
}).refine(value => value.password === value.password_confirmation, {
  message: 'Passwords do not match',
  path: ['password_confirmation']
})
const createSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  email: z.string().email('Enter a valid email address'),
  password: z.string().min(8, 'Password must be at least 8 characters'),
  password_confirmation: z.string().min(8, 'Confirm the password')
}).refine(value => value.password === value.password_confirmation, {
  message: 'Passwords do not match',
  path: ['password_confirmation']
})
const schema = computed(() => props.user === null ? createSchema : editSchema)

const title = computed(() => props.user === null ? 'Add user' : `Edit ${props.user.name}`)

watch(() => props.user, (user) => {
  state.name = user?.name ?? ''
  state.email = user?.email ?? ''
  state.password = ''
  state.password_confirmation = ''
}, { immediate: true })

function onSubmit(event: FormSubmitEvent<FormState>): void {
  if (props.user === null) {
    emit('create', {
      name: event.data.name,
      email: event.data.email,
      password: event.data.password,
      password_confirmation: event.data.password_confirmation
    })

    return
  }

  const payload = event.data.password === ''
    ? { name: event.data.name, email: event.data.email }
    : {
        name: event.data.name,
        email: event.data.email,
        password: event.data.password,
        password_confirmation: event.data.password_confirmation
      }

  emit('update', payload)
}
</script>

<template>
  <UModal
    v-model:open="open"
    :title="title"
  >
    <template #body>
      <UForm
        :schema="schema"
        :state="state"
        :disabled="props.loading"
        class="space-y-4"
        @submit="onSubmit"
      >
        <UFormField name="name" label="Name" required>
          <UInput v-model="state.name" class="w-full" />
        </UFormField>
        <UFormField name="email" label="Email" required>
          <UInput v-model="state.email" type="email" class="w-full" />
        </UFormField>
        <UFormField name="password" :label="props.user === null ? 'Password' : 'New password'" :required="props.user === null">
          <UInput v-model="state.password" type="password" class="w-full" />
        </UFormField>
        <UFormField
          name="password_confirmation"
          :label="props.user === null ? 'Confirm password' : 'Confirm new password'"
          :required="props.user === null"
        >
          <UInput v-model="state.password_confirmation" type="password" class="w-full" />
        </UFormField>
        <div class="flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="subtle"
            @click="open = false"
          />
          <UButton label="Save" type="submit" :loading="props.loading" />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
