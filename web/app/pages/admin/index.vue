<script setup lang="ts">
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useTenantContext } from '~/composables/useTenantContext'
import type { Tenant } from '~/types/auth'
import type { AssetType, Project, TenantHierarchy } from '~/types/admin'

definePageMeta({
  middleware: 'auth'
})

type HomeTenantOverview = {
  tenant: Tenant
  hierarchy: TenantHierarchy
}

type HomeProjectPreview = {
  project: Project
  assetCount: number
  equipmentCount: number
}

type HomeTenantPreview = {
  tenant: Tenant
  projects: HomeProjectPreview[]
}

type HomeNavigationCard = {
  label: string
  value: number
  icon: string
  iconClass: string
  iconContainerClass: string
  to: string
}

const assetCardDefinitions: Array<{
  assetType: AssetType
  label: string
  icon: string
  iconClass: string
  iconContainerClass: string
}> = [
  {
    assetType: 'site',
    label: 'Site',
    icon: 'i-lucide-map-pin',
    iconClass: 'text-emerald-500',
    iconContainerClass: 'bg-emerald-500/10 ring-emerald-500/25'
  },
  {
    assetType: 'factory',
    label: 'Factory',
    icon: 'i-lucide-building-2',
    iconClass: 'text-purple-500',
    iconContainerClass: 'bg-purple-500/10 ring-purple-500/25'
  },
  {
    assetType: 'area',
    label: 'Area',
    icon: 'i-lucide-panels-top-left',
    iconClass: 'text-primary',
    iconContainerClass: 'bg-primary/10 ring-primary/25'
  },
  {
    assetType: 'production_line',
    label: 'Production Line',
    icon: 'i-lucide-workflow',
    iconClass: 'text-amber-500',
    iconContainerClass: 'bg-amber-500/10 ring-amber-500/25'
  },
  {
    assetType: 'station',
    label: 'Station',
    icon: 'i-lucide-cuboid',
    iconClass: 'text-slate-500',
    iconContainerClass: 'bg-slate-500/10 ring-slate-500/25'
  },
  {
    assetType: 'equipment',
    label: 'Equipment',
    icon: 'i-lucide-cog',
    iconClass: 'text-orange-500',
    iconContainerClass: 'bg-orange-500/10 ring-orange-500/25'
  }
]

const { isPlatformAdmin, loadTenants, selectTenant, tenants, activeTenantId } = useTenantContext()
const { loadTenantHierarchy } = useAdmin()
await loadTenants()

const scopeTenantId = ref<string | null>(isPlatformAdmin.value ? null : activeTenantId.value)
const tenantIds = computed(() => tenants.value.map(tenant => tenant.id))
const tenantOptions = computed<Array<{ label: string, value: string | null }>>(() => [
  ...(isPlatformAdmin.value ? [{ label: 'All tenants', value: null }] : []),
  ...tenants.value.map(tenant => ({
    label: `${tenant.name} (${tenant.code})`,
    value: tenant.id
  }))
])

const scopedTenants = computed(() => {
  if (scopeTenantId.value === null) {
    return isPlatformAdmin.value ? tenants.value : []
  }

  const tenant = tenants.value.find(item => item.id === scopeTenantId.value)

  if (tenant === undefined) {
    throw new Error('The selected tenant is not available.')
  }

  return [tenant]
})

const scopeLabel = computed(() => {
  if (scopeTenantId.value === null) {
    return isPlatformAdmin.value ? 'All tenants' : 'No tenant assigned'
  }

  const tenant = tenants.value.find(item => item.id === scopeTenantId.value)

  if (tenant === undefined) {
    throw new Error('The selected tenant is not available.')
  }

  return tenant.name
})

const { data: overviewData, status, error } = await useAsyncData<HomeTenantOverview[]>(
  'home-overview',
  async () => {
    const selectedTenants = scopedTenants.value
    const hierarchies = await Promise.all(
      selectedTenants.map(tenant => loadTenantHierarchy(tenant.id))
    )

    return selectedTenants.map((tenant, index) => {
      const hierarchy = hierarchies[index]

      if (hierarchy === undefined) {
        throw new Error('The Home hierarchy response is incomplete.')
      }

      return { tenant, hierarchy }
    })
  },
  {
    default: (): HomeTenantOverview[] => [],
    watch: [scopeTenantId, tenantIds]
  }
)

watch(activeTenantId, (tenantId) => {
  if (!isPlatformAdmin.value) {
    scopeTenantId.value = tenantId
  }
})

watch(scopeTenantId, (tenantId) => {
  if (tenantId !== null && tenants.value.some(tenant => tenant.id === tenantId)) {
    selectTenant(tenantId)
  }
})

const tenantOverviews = computed(() => overviewData.value ?? [])
const summary = computed(() => {
  const projects = tenantOverviews.value.flatMap(item => item.hierarchy.projects)
  const assets = tenantOverviews.value.flatMap(item => item.hierarchy.assets)
  const assetCounts: Record<AssetType, number> = {
    site: 0,
    factory: 0,
    area: 0,
    production_line: 0,
    station: 0,
    equipment: 0
  }

  for (const asset of assets) {
    assetCounts[asset.asset_type] += 1
  }

  return {
    projects: projects.length,
    assetCounts
  }
})

const hierarchyPreview = computed<HomeTenantPreview[]>(() => tenantOverviews.value.map(({ tenant, hierarchy }) => ({
  tenant,
  projects: hierarchy.projects.map((project) => {
    const projectAssets = hierarchy.assets.filter(asset => asset.project_id === project.id)

    return {
      project,
      assetCount: projectAssets.length,
      equipmentCount: projectAssets.filter(asset => asset.asset_type === 'equipment').length
    }
  })
})))

const iiotTreeLink = computed(() => scopeTenantId.value === null
  ? '/admin/iiot-tree'
  : `/admin/iiot-tree?tenant_id=${scopeTenantId.value}`)
const navigationCards = computed<HomeNavigationCard[]>(() => {
  const cards: HomeNavigationCard[] = [
    {
      label: 'Projects',
      value: summary.value.projects,
      icon: 'i-lucide-folder-kanban',
      iconClass: 'text-blue-500',
      iconContainerClass: 'bg-blue-500/10 ring-blue-500/25',
      to: '/admin/projects'
    }
  ]

  cards.push(...assetCardDefinitions.map(card => ({
    label: card.label,
    value: summary.value.assetCounts[card.assetType],
    icon: card.icon,
    iconClass: card.iconClass,
    iconContainerClass: card.iconContainerClass,
    to: '/admin/assets'
  })))

  return cards
})
const errorMessage = computed(() => {
  if (!(error.value instanceof ApiError)) {
    return 'The Home overview could not be loaded.'
  }

  return error.value.message
})
</script>

<template>
  <UDashboardPanel id="home" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Home">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <USelect
            v-if="tenantOptions.length > 0"
            v-model="scopeTenantId"
            :items="tenantOptions"
            class="min-w-48"
            aria-label="Dashboard scope"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-6">
        <UAlert
          v-if="status === 'error'"
          color="error"
          variant="subtle"
          title="Unable to load Home overview"
          :description="errorMessage"
        />

        <UAlert
          v-if="status !== 'pending' && tenants.length === 0"
          color="warning"
          variant="subtle"
          title="No tenant is assigned"
          description="Your account is not currently assigned to a Tenant. Contact an administrator to request access."
        />

        <div class="flex flex-col gap-3">
          <div>
            <p class="text-sm text-muted">
              Current scope
            </p>
            <p class="text-lg font-semibold text-highlighted">
              {{ scopeLabel }}
            </p>
          </div>

          <UPageGrid class="sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-4 sm:gap-6 xl:gap-px">
            <UPageCard
              v-for="card in navigationCards"
              :key="card.label"
              :to="card.to"
              :icon="card.icon"
              :title="card.label"
              variant="subtle"
              :ui="{
                container: 'gap-y-1.5',
                wrapper: 'items-start',
                leading: `p-2.5 rounded-full ${card.iconContainerClass} flex-col`,
                leadingIcon: `size-5 shrink-0 ${card.iconClass}`,
                title: 'font-normal text-muted text-xs uppercase'
              }"
              class="xl:rounded-none first:rounded-l-lg last:rounded-r-lg hover:z-1"
            >
              <div class="flex items-center gap-2">
                <USkeleton v-if="status === 'pending'" class="h-8 w-12" />
                <span v-else class="text-2xl font-semibold text-highlighted">
                  {{ card.value }}
                </span>
              </div>
            </UPageCard>
          </UPageGrid>
        </div>

        <UCard
          variant="soft"
          :ui="{
            root: 'bg-transparent divide-y-0',
            header: 'px-0 sm:px-0',
            body: 'px-0 sm:px-0'
          }"
        >
          <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 class="font-semibold text-highlighted">
                  Hierarchy preview
                </h2>
                <p class="text-sm text-muted">
                  Tenant - Project with Asset and Equipment counts.
                </p>
              </div>
              <UButton
                label="View full tree"
                icon="i-lucide-arrow-up-right"
                variant="link"
                :to="iiotTreeLink"
              />
            </div>
          </template>

          <div v-if="status === 'pending'" class="space-y-4">
            <div v-for="index in 3" :key="index" class="space-y-2">
              <USkeleton class="h-5 w-48" />
              <USkeleton class="h-10 w-full" />
            </div>
          </div>

          <div v-else-if="hierarchyPreview.length === 0" class="rounded-lg border border-dashed border-default p-8 text-center text-sm text-muted">
            No Project is available in the current scope.
          </div>

          <div v-else class="space-y-5">
            <div v-for="tenantPreview in hierarchyPreview" :key="tenantPreview.tenant.id" class="space-y-2">
              <NuxtLink
                class="flex items-center gap-2 font-semibold text-highlighted hover:text-primary"
                :to="`/admin/iiot-tree?tenant_id=${tenantPreview.tenant.id}`"
              >
                <UIcon name="i-lucide-building-2" class="size-4 text-emerald-500" />
                {{ tenantPreview.tenant.name }}
              </NuxtLink>

              <div v-if="tenantPreview.projects.length > 0" class="ml-6 space-y-2 border-l border-default pl-4">
                <div
                  v-for="projectPreview in tenantPreview.projects"
                  :key="projectPreview.project.id"
                  class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-default bg-elevated/20 px-4 py-3"
                >
                  <div class="flex items-center gap-2">
                    <UIcon name="i-lucide-folder-kanban" class="size-4 text-blue-500" />
                    <span class="font-medium text-highlighted">{{ projectPreview.project.name }}</span>
                  </div>
                  <div class="flex items-center gap-2 text-xs text-muted">
                    <UBadge variant="subtle">
                      {{ projectPreview.assetCount }} assets
                    </UBadge>
                    <UBadge color="warning" variant="subtle">
                      {{ projectPreview.equipmentCount }} equipment
                    </UBadge>
                  </div>
                </div>
              </div>

              <p v-else class="ml-6 border-l border-default pl-4 text-sm text-muted">
                No Project is available for this Tenant.
              </p>
            </div>
          </div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
