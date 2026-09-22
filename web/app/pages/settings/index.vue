<script setup lang="ts">
import * as z from 'zod'
import type { FormError, FormSubmitEvent } from '@nuxt/ui'
import { ApiError, useApi } from '~/composables/useApi'
import { authUserResponseSchema } from '~/types/auth'

definePageMeta({
  middleware: 'auth'
})

const colorMode = useColorMode()
const { user } = useAuth()
const { timezone, utcLabel, detectBrowserTimezone } = useDateTime()
const api = useApi()
const toast = useToast()
const profileForm = ref<{ setErrors: (errors: FormError[]) => void }>()
const isSubmitting = ref(false)
const timezoneModalOpen = ref(false)

function onAutoDetectTimezone(): void {
  const detected = detectBrowserTimezone()
  timezone.value = detected
  toast.add({
    title: 'Timezone detected',
    description: `Timezone set to ${detected}.`,
    icon: 'i-lucide-check',
    color: 'success'
  })
}

const currentUser = computed(() => {
  if (user.value === null) {
    throw new Error('Settings requires an authenticated user.')
  }

  return user.value
})

const profileSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  email: z.string().email('Enter a valid email address').max(255, 'Email is too long')
})

type ProfileSchema = z.output<typeof profileSchema>

const profile = reactive<ProfileSchema>({
  name: currentUser.value.name,
  email: currentUser.value.email
})

async function onSubmit(event: FormSubmitEvent<ProfileSchema>): Promise<void> {
  isSubmitting.value = true

  try {
    const response = await api.request('/api/me', authUserResponseSchema, {
      body: event.data,
      method: 'PATCH'
    })

    user.value = response.data
    profile.name = response.data.name
    profile.email = response.data.email

    toast.add({
      title: 'Success',
      description: 'Your profile has been updated.',
      icon: 'i-lucide-check',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    const errors = Object.entries(error.errors).flatMap(([name, messages]) => messages.map(message => ({ name, message })))
    profileForm.value?.setErrors(errors)
    toast.add({
      title: 'Unable to update profile',
      description: error.message,
      color: 'error'
    })
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <UForm
    id="settings"
    ref="profileForm"
    :schema="profileSchema"
    :state="profile"
    :disabled="isSubmitting"
    @submit="onSubmit"
  >
    <UPageCard
      title="Profile"
      description="These informations will be displayed publicly."
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        form="settings"
        label="Save changes"
        color="neutral"
        type="submit"
        :loading="isSubmitting"
        class="w-fit lg:ms-auto"
      />
    </UPageCard>

    <UPageCard variant="subtle">
      <UFormField
        name="name"
        label="Name"
        description="Will appear on receipts, invoices, and other communication."
        required
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <UInput
          v-model="profile.name"
          autocomplete="name"
          class="w-full sm:max-w-xs"
          :ui="{ base: 'truncate' }"
        />
      </UFormField>

      <USeparator />

      <UFormField
        name="email"
        label="Email"
        description="Used to sign in, for email receipts and product updates."
        required
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <UInput
          v-model="profile.email"
          type="email"
          autocomplete="email"
          class="w-full sm:max-w-xs"
          :ui="{ base: 'truncate' }"
        />
      </UFormField>

      <USeparator />

      <UFormField
        label="Role"
        description="Roles are managed by the tenant and cannot be changed here."
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <div class="flex flex-col gap-2 w-full sm:max-w-xs">
          <div
            v-for="tenant in currentUser.tenants"
            :key="tenant.id"
            class="flex items-center justify-between gap-4 rounded-md border border-default px-3 py-2"
          >
            <span class="truncate">{{ tenant.name }}</span>
            <UBadge
              color="neutral"
              variant="subtle"
            >
              {{ tenant.role }}
            </UBadge>
          </div>
        </div>
      </UFormField>
    </UPageCard>
  </UForm>

  <UPageCard variant="subtle" class="mt-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <div class="font-medium text-highlighted">
          Timezone
        </div>
        <div class="text-sm text-muted">
          Configure your display timezone.
        </div>
      </div>

      <div class="flex items-center gap-2 shrink-0">
        <UButton
          :label="`${utcLabel} (${timezone})`"
          icon="i-lucide-globe"
          color="neutral"
          variant="outline"
          size="sm"
          class="min-w-0"
          @click="timezoneModalOpen = true"
        />
        <UButton
          icon="i-lucide-locate"
          color="neutral"
          variant="outline"
          size="sm"
          title="Auto-detect from browser"
          aria-label="Auto-detect from browser"
          class="shrink-0"
          @click="onAutoDetectTimezone"
        />
      </div>
    </div>
  </UPageCard>

  <UPageCard variant="subtle" class="mt-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <div class="font-medium text-highlighted">
          Theme
        </div>
        <div class="text-sm text-muted">
          Choose your preferred color theme.
        </div>
      </div>

      <div class="flex items-center gap-2 shrink-0">
        <UButton
          label="Light"
          icon="i-lucide-sun"
          :color="colorMode.preference === 'light' ? 'primary' : 'neutral'"
          :variant="colorMode.preference === 'light' ? 'solid' : 'outline'"
          size="sm"
          @click="colorMode.preference = 'light'"
        />
        <UButton
          label="Dark"
          icon="i-lucide-moon"
          :color="colorMode.preference === 'dark' ? 'primary' : 'neutral'"
          :variant="colorMode.preference === 'dark' ? 'solid' : 'outline'"
          size="sm"
          @click="colorMode.preference = 'dark'"
        />
        <UButton
          label="System"
          icon="i-lucide-monitor"
          :color="colorMode.preference === 'system' ? 'primary' : 'neutral'"
          :variant="colorMode.preference === 'system' ? 'solid' : 'outline'"
          size="sm"
          @click="colorMode.preference = 'system'"
        />
      </div>
    </div>
  </UPageCard>

  <TimezoneModal v-model:open="timezoneModalOpen" />
</template>
