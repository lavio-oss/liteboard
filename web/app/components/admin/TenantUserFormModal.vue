<script setup lang="ts">
import type { FormSubmitEvent } from '@nuxt/ui'
import * as z from 'zod'
import type { Role, TenantUser, TenantUserCreateInput, TenantUserUpdateInput } from '~/types/auth'

const props = withDefaults(defineProps<{
  user: TenantUser | null
  roles: Role[]
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
  role_id: string
}

const state = reactive<FormState>({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  role_id: ''
})
const roleOptions = computed(() => props.roles.map(role => ({ label: role.name, value: role.id })))
const editSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  email: z.string().email('Enter a valid email address'),
  password: z.string().refine(value => value === '' || value.length >= 8, 'Password must be at least 8 characters'),
  password_confirmation: z.string(),
  role_id: z.string().uuid('Choose a role')
}).refine(value => value.password === value.password_confirmation, {
  message: 'Passwords do not match',
  path: ['password_confirmation']
})
const createSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  email: z.string().email('Enter a valid email address'),
  password: z.string().min(8, 'Password must be at least 8 characters'),
  password_confirmation: z.string().min(8, 'Confirm the password'),
  role_id: z.string().uuid('Choose a role')
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
  state.role_id = user?.role_id ?? ''
}, { immediate: true })

function onSubmit(event: FormSubmitEvent<FormState>): void {
  if (props.user === null) {
    emit('create', {
      name: event.data.name,
      email: event.data.email,
      password: event.data.password,
      password_confirmation: event.data.password_confirmation,
      role_id: event.data.role_id
    })

    return
  }

  const payload = event.data.password === ''
    ? { name: event.data.name, email: event.data.email, role_id: event.data.role_id }
    : {
        name: event.data.name,
        email: event.data.email,
        password: event.data.password,
        password_confirmation: event.data.password_confirmation,
        role_id: event.data.role_id
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
          <UInput
            v-model="state.name"
            class="w-full"
            placeholder="Enter name"
          />
        </UFormField>
        <UFormField name="email" label="Email" required>
          <UInput
            v-model="state.email"
            type="email"
            class="w-full"
            placeholder="Enter email address"
          />
        </UFormField>
        <UFormField name="role_id" label="Tenant role" required>
          <USelect
            v-model="state.role_id"
            :items="roleOptions"
            class="w-full"
            placeholder="Choose a role"
          />
        </UFormField>
        <UFormField name="password" :label="props.user === null ? 'Password' : 'New password'" :required="props.user === null">
          <UInput
            v-model="state.password"
            type="password"
            class="w-full"
            :placeholder="props.user === null ? 'Enter password' : 'Enter new password'"
          />
        </UFormField>
        <UFormField
          name="password_confirmation"
          :label="props.user === null ? 'Confirm password' : 'Confirm new password'"
          :required="props.user === null"
        >
          <UInput
            v-model="state.password_confirmation"
            type="password"
            class="w-full"
            :placeholder="props.user === null ? 'Confirm password' : 'Confirm new password'"
          />
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
