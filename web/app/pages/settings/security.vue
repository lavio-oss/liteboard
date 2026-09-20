<script setup lang="ts">
import * as z from 'zod'
import type { FormError, FormSubmitEvent } from '@nuxt/ui'
import { ApiError, useApi } from '~/composables/useApi'

definePageMeta({
  middleware: 'auth'
})

const api = useApi()
const toast = useToast()
const passwordForm = ref<{ setErrors: (errors: FormError[]) => void }>()
const isSubmitting = ref(false)
const emptyResponseSchema = z.unknown()

const passwordSchema = z.object({
  current_password: z.string().min(1, 'Enter your current password'),
  password: z.string().min(8, 'Password must be at least 8 characters'),
  password_confirmation: z.string().min(8, 'Password must be at least 8 characters')
}).refine(state => state.password !== state.current_password, {
  path: ['password'],
  message: 'New password must be different from the current password'
}).refine(state => state.password === state.password_confirmation, {
  path: ['password_confirmation'],
  message: 'Passwords do not match'
})

type PasswordSchema = z.output<typeof passwordSchema>

const password = reactive<Partial<PasswordSchema>>({
  current_password: '',
  password: '',
  password_confirmation: ''
})

async function onSubmit(event: FormSubmitEvent<PasswordSchema>): Promise<void> {
  isSubmitting.value = true

  try {
    await api.request('/api/me/password', emptyResponseSchema, {
      body: event.data,
      method: 'PATCH'
    })

    password.current_password = ''
    password.password = ''
    password.password_confirmation = ''

    toast.add({
      title: 'Success',
      description: 'Your password has been updated.',
      icon: 'i-lucide-check',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    const errors = Object.entries(error.errors).flatMap(([name, messages]) => messages.map(message => ({ name, message })))
    passwordForm.value?.setErrors(errors)
    toast.add({
      title: 'Unable to update password',
      description: error.message,
      color: 'error'
    })
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <UPageCard
    title="Password"
    description="Confirm your current password before setting a new one."
    variant="subtle"
  >
    <UForm
      ref="passwordForm"
      :schema="passwordSchema"
      :state="password"
      :disabled="isSubmitting"
      class="flex flex-col gap-4 max-w-xs"
      @submit="onSubmit"
    >
      <UFormField name="current_password" label="Current password">
        <UInput
          v-model="password.current_password"
          type="password"
          autocomplete="current-password"
          placeholder="Current password"
          class="w-full"
        />
      </UFormField>

      <UFormField name="password" label="New password">
        <UInput
          v-model="password.password"
          type="password"
          autocomplete="new-password"
          placeholder="New password"
          class="w-full"
        />
      </UFormField>

      <UFormField name="password_confirmation" label="Confirm new password">
        <UInput
          v-model="password.password_confirmation"
          type="password"
          autocomplete="new-password"
          placeholder="Confirm new password"
          class="w-full"
        />
      </UFormField>

      <UButton
        label="Update"
        class="w-fit"
        type="submit"
        :loading="isSubmitting"
      />
    </UForm>
  </UPageCard>
</template>
