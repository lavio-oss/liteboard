<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

definePageMeta({
  middleware: 'auth'
})

const links = [[{
  label: 'Projects',
  icon: 'i-lucide-folder-kanban',
  to: '/masterdata/projects'
}, {
  label: 'Factories',
  icon: 'i-lucide-building-2',
  to: '/masterdata/factories'
}, {
  label: 'Production Lines',
  icon: 'i-lucide-git-branch',
  to: '/masterdata/production-lines'
}]] satisfies NavigationMenuItem[][]

const route = useRoute()
const { user } = useAuth()
const addRequested = useState('masterdata:add-requested', () => false)

const canManage = computed(() => {
  const tenant = user.value?.tenants[0]

  return tenant !== undefined && (tenant.role === 'owner' || tenant.role === 'admin')
})

const addLabel = computed(() => {
  if (route.path.endsWith('/factories')) {
    return 'Add factory'
  }

  if (route.path.endsWith('/production-lines')) {
    return 'Add production line'
  }

  return 'Add project'
})

const showAdd = computed(() => route.path !== '/masterdata' && canManage.value)

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
