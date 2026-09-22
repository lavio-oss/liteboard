<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

definePageMeta({
  middleware: 'auth'
})

const links = [[{
  label: 'Users',
  icon: 'i-lucide-users',
  to: '/masterdata/users',
  exact: true
}, {
  label: 'Projects',
  icon: 'i-lucide-folder-kanban',
  to: '/masterdata/projects'
}, {
  label: 'Assets',
  icon: 'i-lucide-network',
  to: '/masterdata/assets'
}]] satisfies NavigationMenuItem[][]

const route = useRoute()
const { canManageActiveTenant, isPlatformAdmin, loadTenants, tenants } = useTenantContext()
await loadTenants()
const addRequested = useState('masterdata:add-requested', () => false)

const canManageAnyTenant = computed(() => isPlatformAdmin.value
  || tenants.value.some(tenant => tenant.role === 'owner' || tenant.role === 'admin'))
const canManage = computed(() => route.path.endsWith('/users')
  ? canManageActiveTenant.value
  : canManageAnyTenant.value)

const addLabel = computed(() => {
  if (route.path.endsWith('/users')) {
    return 'Add user'
  }

  if (route.path.endsWith('/assets')) {
    return 'Add asset'
  }

  return 'Add project'
})

const showAdd = computed(() => [
  '/masterdata/users',
  '/masterdata/projects',
  '/masterdata/assets'
].includes(route.path) && canManage.value)

function requestAdd(): void {
  addRequested.value = true
}
</script>

<template>
  <UDashboardPanel id="masterdata" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Masterdata">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="showAdd"
            :label="addLabel"
            icon="i-lucide-plus"
            @click="requestAdd"
          />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <UNavigationMenu :items="links" highlight class="-mx-1 flex-1" />
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4 sm:gap-6 lg:gap-12 w-full">
        <NuxtPage />
      </div>
    </template>
  </UDashboardPanel>
</template>
