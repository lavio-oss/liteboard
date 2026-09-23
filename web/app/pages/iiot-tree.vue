<script setup lang="ts">
import { z } from 'zod'
import { ApiError, useApi } from '~/composables/useApi'
import { tenantCollectionResponseSchema } from '~/types/auth'

definePageMeta({
  middleware: 'auth'
})

const api = useApi()
const route = useRoute()
const tenantQuerySchema = z.object({
  tenant_id: z.coerce.number().int().positive().optional()
})
const { data: response, status, error } = await useAsyncData('iiot-tree-tenants', () =>
  api.request('/api/tenants', tenantCollectionResponseSchema)
)

const tenants = computed(() => response.value?.data ?? [])
const requestedTenantId = computed(() => tenantQuerySchema.parse(route.query).tenant_id ?? null)
const initialTenantId = computed(() => {
  const tenantId = requestedTenantId.value

  return tenantId !== null && tenants.value.some(tenant => tenant.id === tenantId)
    ? tenantId
    : null
})
const errorMessage = computed(() => {
  if (!(error.value instanceof ApiError)) {
    return 'The IIoT tree could not be loaded.'
  }

  return error.value.message
})
</script>

<template>
  <UDashboardPanel id="iiot-tree" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="IIoT Tree">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="status === 'error'"
        color="error"
        variant="subtle"
        title="Unable to load IIoT tree"
        :description="errorMessage"
        class="mb-4"
      />

      <TenantHierarchyFlow
        :tenants="tenants"
        :initial-tenant-id="initialTenantId"
      />
    </template>
  </UDashboardPanel>
</template>
