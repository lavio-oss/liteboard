<script setup lang="ts">
import * as z from 'zod'
import type { FormError, FormSubmitEvent } from '@nuxt/ui'
import { ApiError, useApi } from '~/composables/useApi'
import { useTenantContext } from '~/composables/useTenantContext'
import { tenantResponseSchema } from '~/types/auth'

definePageMeta({
  middleware: 'auth'
})

const api = useApi()
const toast = useToast()
const { activeTenant, loadTenants } = useTenantContext()
await loadTenants()

const organizationForm = ref<{ setErrors: (errors: FormError[]) => void }>()
const isSubmitting = ref(false)
const canUpdate = computed(() => activeTenant.value?.permissions.includes('tenant.update') === true)

const organizationSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  code: z.string().min(1, 'Code is required').max(64, 'Code is too long'),
  active: z.boolean()
})

type OrganizationSchema = z.output<typeof organizationSchema>

const organization = reactive<OrganizationSchema>({ name: '', code: '', active: true })

watch(activeTenant, (tenant) => {
  organization.name = tenant?.name ?? ''
  organization.code = tenant?.code ?? ''
  organization.active = tenant?.status !== 'inactive'
}, { immediate: true })

async function onSubmit(event: FormSubmitEvent<OrganizationSchema>): Promise<void> {
  if (activeTenant.value === null) {
    throw new Error('A tenant is required before saving.')
  }

  isSubmitting.value = true

  try {
    await api.request(`/api/tenants/${activeTenant.value.id}`, tenantResponseSchema, {
      body: { name: event.data.name, code: event.data.code, status: event.data.active ? 'active' : 'inactive' },
      method: 'PATCH'
    })
    await loadTenants(true)
    toast.add({
      title: 'Success',
      description: 'The organization has been updated.',
      icon: 'i-lucide-check',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    const errors = Object.entries(error.errors).flatMap(([name, messages]) => messages.map(message => ({ name, message })))
    organizationForm.value?.setErrors(errors)
    toast.add({
      title: 'Unable to update the organization',
      description: error.message,
      color: 'error'
    })
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="tenants" :ui="{ body: 'lg:py-12' }">
    <template #header>
      <UDashboardNavbar title="Organization">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4 sm:gap-6 lg:gap-12 w-full lg:max-w-2xl mx-auto">
        <UPageCard
          title="Organization"
          description="The name and code identify your organization across the platform."
          variant="subtle"
        >
          <UForm
            ref="organizationForm"
            :schema="organizationSchema"
            :state="organization"
            :disabled="isSubmitting || !canUpdate"
            class="flex flex-col gap-4 max-w-xs"
            @submit="onSubmit"
          >
            <UFormField name="name" label="Name">
              <UInput
                v-model="organization.name"
                placeholder="Name"
                class="w-full"
              />
            </UFormField>

            <UFormField name="code" label="Code">
              <UInput
                v-model="organization.code"
                placeholder="Code"
                class="w-full"
              />
            </UFormField>

            <UFormField name="active" label="Status">
              <USwitch
                v-model="organization.active"
                label="Active"
              />
            </UFormField>

            <UButton
              v-if="canUpdate"
              label="Update"
              class="w-fit"
              type="submit"
              :loading="isSubmitting"
            />
          </UForm>
        </UPageCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
