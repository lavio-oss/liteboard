<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, AuthFormField } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'

definePageMeta({
  layout: false,
  middleware: 'guest'
})

const toast = useToast()
const { login } = useAuth()
const isSubmitting = ref(false)

const fields: AuthFormField[] = [{
  name: 'email',
  type: 'email',
  label: 'Email',
  placeholder: 'Enter your email',
  required: true
}, {
  name: 'password',
  label: 'Password',
  type: 'password',
  placeholder: 'Enter your password',
  required: true
}, {
  name: 'remember',
  label: 'Remember me',
  type: 'checkbox'
}]

const providers = [{
  label: 'Google',
  icon: 'i-simple-icons-google',
  onClick: () => {
    toast.add({
      title: 'Google OAuth',
      description: 'Google login is not configured yet.',
      color: 'neutral'
    })
  }
}, {
  label: 'GitHub',
  icon: 'i-simple-icons-github',
  onClick: () => {
    toast.add({
      title: 'GitHub OAuth',
      description: 'GitHub login is not configured yet.',
      color: 'neutral'
    })
  }
}]

const schema = z.object({
  email: z.email('Invalid email'),
  password: z.string().min(8, 'Must be at least 8 characters')
})

type Schema = z.output<typeof schema>

async function onSubmit(event: FormSubmitEvent<Schema>): Promise<void> {
  isSubmitting.value = true

  try {
    await login(event.data)
    await navigateTo('/')
  } catch (error: unknown) {
    const description = error instanceof ApiError && error.status === 401
      ? 'Invalid credentials. Please try again.'
      : error instanceof ApiError && error.status === 419
        ? 'Your session expired. Please try again.'
        : error instanceof ApiError && error.status === 429
          ? 'Too many login attempts. Please try again later.'
          : 'Unable to sign in. Please try again.'

    toast.add({
      title: 'Unable to sign in',
      description,
      color: 'error'
    })
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center gap-4 p-4">
    <UPageCard class="w-full max-w-md">
      <UAuthForm
        :schema="schema"
        :fields="fields"
        :providers="providers"
        :loading="isSubmitting"
        title="Login"
        description="Enter your credentials to access your account."
        icon="i-lucide-user"
        @submit="onSubmit"
      />
    </UPageCard>
  </div>
</template>
