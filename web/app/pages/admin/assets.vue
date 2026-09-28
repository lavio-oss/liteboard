<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useTenantContext } from '~/composables/useTenantContext'
import type { AdminTableRow, Asset, AssetInput, AssetType } from '~/types/admin'

definePageMeta({
  middleware: 'auth'
})

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const toast = useToast()
const { isPlatformAdmin, loadTenants, tenants } = useTenantContext()
await loadTenants()

const tenantIds = computed(() => tenants.value.map(tenant => tenant.id))
const manageableTenantIds = computed(() => new Set(
  tenants.value
    .filter(tenant => isPlatformAdmin.value || tenant.role === 'owner' || tenant.role === 'admin')
    .map(tenant => tenant.id)
))
const canCreate = computed(() => isPlatformAdmin.value || manageableTenantIds.value.size > 0)
const addRequested = useState('admin:add-requested', () => false)
const { createAsset, deleteAsset, loadTenantHierarchy, updateAsset } = useAdmin()
const formOpen = ref(false)
const formLoading = ref(false)
const selectedProjectId = ref<string | null>(null)
const selectedParentId = ref<string | null>(null)
const editingAsset = ref<Asset | null>(null)
const deleteOpen = ref(false)
const deleteLoading = ref(false)
const deletingAsset = ref<Asset | null>(null)
const assetTypeFilter = ref<AssetType | 'all'>('all')
const projectFilter = ref<string | null>(null)
const tenantFilter = ref<string | null>(null)

function resetAssetFilters(): void {
  assetTypeFilter.value = 'all'
  projectFilter.value = null
  tenantFilter.value = null
}

const assetTypeLabels: Record<AssetType, string> = {
  site: 'Site',
  factory: 'Factory',
  area: 'Area',
  production_line: 'Production Line',
  station: 'Station',
  equipment: 'Equipment'
}

const assetTypeColors: Record<AssetType, 'success' | 'secondary' | 'primary' | 'warning' | 'neutral' | 'error'> = {
  site: 'success',
  factory: 'secondary',
  area: 'primary',
  production_line: 'warning',
  station: 'neutral',
  equipment: 'error'
}

const assetTypeOptions: Array<{ label: string, value: AssetType | 'all' }> = [
  { label: 'All asset types', value: 'all' },
  ...Object.entries(assetTypeLabels).map(([value, label]) => ({
    label,
    value: value as AssetType
  }))
]

const { data: response, status, error, refresh } = await useAsyncData('admin-assets', async () => {
  const hierarchies = await Promise.all(
    tenants.value.map(tenant => loadTenantHierarchy(tenant.id))
  )

  return {
    assets: hierarchies.flatMap(hierarchy => hierarchy.assets),
    projects: hierarchies.flatMap(hierarchy => hierarchy.projects)
  }
}, { watch: [tenantIds] })

const assets = computed(() => response.value?.assets ?? [])
const projects = computed(() => response.value?.projects ?? [])
const projectOptions = computed(() => [
  ...projects.value.map(project => ({ label: project.name, value: project.id }))
])
const projectFilterOptions = computed(() => [
  { label: 'All projects', value: null },
  ...projectOptions.value
])
const tenantFilterOptions = computed(() => [
  { label: 'All tenants', value: null },
  ...tenants.value.map(tenant => ({ label: tenant.name, value: tenant.id }))
])

const disallowedParentIds = computed(() => {
  const editingId = editingAsset.value?.id
  const disallowed = new Set<string>()

  if (editingId === undefined) {
    return disallowed
  }

  disallowed.add(editingId)

  let changed = true
  while (changed) {
    changed = false

    for (const asset of assets.value) {
      if (asset.parent_asset_id !== null && disallowed.has(asset.parent_asset_id) && !disallowed.has(asset.id)) {
        disallowed.add(asset.id)
        changed = true
      }
    }
  }

  return disallowed
})

const parentOptions = computed(() => [
  { label: 'Root asset', value: null },
  ...assets.value
    .filter(asset => asset.project_id === selectedProjectId.value)
    .filter(asset => !disallowedParentIds.value.has(asset.id))
    .map(asset => ({ label: asset.name, value: asset.id }))
])

const filteredAssets = computed(() => assets.value.filter((asset) => {
  const matchesType = assetTypeFilter.value === 'all' || asset.asset_type === assetTypeFilter.value
  const matchesProject = projectFilter.value === null || asset.project_id === projectFilter.value
  const matchesTenant = tenantFilter.value === null || tenantIdForProject(asset.project_id) === tenantFilter.value

  return matchesType && matchesProject && matchesTenant
}))

function tenantIdForProject(projectId: string): string {
  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The asset project is not available.')
  }

  return project.tenant_id
}

const rows = computed<AdminTableRow[]>(() => filteredAssets.value)

function projectNameFor(projectId: string | undefined): string {
  if (projectId === undefined) {
    throw new Error('The asset project is missing.')
  }

  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The asset project is not available.')
  }

  return project.name
}

function parentNameFor(parentId: string | null | undefined): string {
  if (parentId === null) {
    return 'Root'
  }

  if (parentId === undefined) {
    throw new Error('The asset parent is missing.')
  }

  const parent = assets.value.find(item => item.id === parentId)

  if (parent === undefined) {
    throw new Error('The asset parent is not available.')
  }

  return parent.name
}

function assetTypeLabelFor(assetType: AssetType | undefined): string {
  if (assetType === undefined) {
    throw new Error('The asset type is missing.')
  }

  return assetTypeLabels[assetType]
}

function openCreate(): void {
  editingAsset.value = null
  selectedProjectId.value = null
  selectedParentId.value = null
  formOpen.value = true
}

function openEdit(assetId: string): void {
  const asset = assets.value.find(item => item.id === assetId)

  if (asset === undefined) {
    throw new Error('The selected asset is not available.')
  }

  editingAsset.value = asset
  selectedProjectId.value = asset.project_id
  selectedParentId.value = asset.parent_asset_id
  formOpen.value = true
}

function openDelete(assetId: string): void {
  const asset = assets.value.find(item => item.id === assetId)

  if (asset === undefined) {
    throw new Error('The selected asset is not available.')
  }

  deletingAsset.value = asset
  deleteOpen.value = true
}

async function saveAsset(payload: AssetInput): Promise<void> {
  if (selectedProjectId.value === null) {
    toast.add({
      title: 'Project is required',
      description: 'Select a project before saving the asset.',
      color: 'error'
    })

    return
  }

  formLoading.value = true

  try {
    const projectTenantId = tenantIdForProject(selectedProjectId.value)

    if (editingAsset.value === null) {
      await createAsset(projectTenantId, selectedProjectId.value, payload)
    } else {
      await updateAsset(projectTenantId, editingAsset.value.project_id, editingAsset.value.id, payload)
    }

    await refresh()
    formOpen.value = false
    toast.add({
      title: editingAsset.value === null ? 'Asset created' : 'Asset updated',
      description: 'The asset has been saved.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to save asset',
      description: error.message,
      color: 'error'
    })
  } finally {
    formLoading.value = false
  }
}

async function deleteSelectedAsset(): Promise<void> {
  if (deletingAsset.value === null) {
    throw new Error('An asset is required before deletion.')
  }

  deleteLoading.value = true

  try {
    await deleteAsset(
      tenantIdForProject(deletingAsset.value.project_id),
      deletingAsset.value.project_id,
      deletingAsset.value.id
    )
    await refresh()
    deleteOpen.value = false
    toast.add({
      title: 'Asset deleted',
      description: 'The asset was deleted. Its children remain as root assets.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to delete asset',
      description: error.message,
      color: 'error'
    })
  } finally {
    deleteLoading.value = false
  }
}

watch(addRequested, (requested) => {
  if (requested) {
    addRequested.value = false
    openCreate()
  }
})

const columns: TableColumn<AdminTableRow>[] = [
  {
    id: 'sequence',
    header: 'No.',
    cell: ({ row }) => row.index + 1
  },
  {
    id: 'name',
    accessorKey: 'name',
    header: 'Name',
    cell: ({ row }) => h('span', { class: 'font-medium text-highlighted' }, row.original.name)
  },
  {
    id: 'asset_type',
    accessorKey: 'asset_type',
    header: 'Asset type',
    cell: ({ row }) => {
      const assetType = row.original.asset_type

      if (assetType === undefined) {
        throw new Error('The asset type is missing.')
      }

      return h(
        UBadge,
        { class: 'capitalize', color: assetTypeColors[assetType], variant: 'subtle' },
        () => assetTypeLabelFor(assetType)
      )
    }
  },
  {
    id: 'code',
    accessorKey: 'code',
    header: 'Code'
  },
  {
    id: 'project_id',
    accessorKey: 'project_id',
    header: 'Project',
    cell: ({ row }) => projectNameFor(row.original.project_id)
  },
  {
    id: 'parent_asset_id',
    accessorKey: 'parent_asset_id',
    header: 'Parent',
    cell: ({ row }) => parentNameFor(row.original.parent_asset_id)
  },
  {
    id: 'location',
    accessorKey: 'location',
    header: 'Location',
    cell: ({ row }) => row.original.location ?? '—'
  },
  {
    id: 'status',
    accessorKey: 'status',
    header: 'Status',
    cell: ({ row }) => h(
      UBadge,
      {
        class: 'capitalize',
        color: row.original.status === 'active' ? 'success' : 'neutral',
        variant: 'subtle'
      },
      () => row.original.status
    )
  },
  {
    id: 'created_at',
    accessorKey: 'created_at',
    header: 'Created',
    cell: ({ row }) => {
      if (row.original.created_at === undefined) {
        throw new Error('The asset created_at value is missing.')
      }

      return formatDateTime(row.original.created_at)
    }
  },
  {
    id: 'actions',
    header: '',
    cell: ({ row }) => row.original.project_id !== undefined
      && manageableTenantIds.value.has(tenantIdForProject(row.original.project_id))
      ? h('div', { class: 'flex justify-end gap-1' }, [
          h(UButton, {
            'icon': 'i-lucide-pencil',
            'color': 'neutral',
            'variant': 'ghost',
            'aria-label': `Edit ${row.original.name}`,
            'onClick': () => openEdit(row.original.id)
          }),
          h(UButton, {
            'icon': 'i-lucide-trash-2',
            'color': 'error',
            'variant': 'ghost',
            'aria-label': `Delete ${row.original.name}`,
            'onClick': () => openDelete(row.original.id)
          })
        ])
      : null
  }
]

const errorMessage = computed(() => {
  if (!(error.value instanceof ApiError)) {
    return 'The assets could not be loaded.'
  }

  return error.value.message
})
</script>

<template>
  <UDashboardPanel id="assets" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Assets">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="canCreate"
            label="Add asset"
            icon="i-lucide-plus"
            @click="openCreate"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="status === 'error'"
          color="error"
          variant="subtle"
          title="Unable to load assets"
          :description="errorMessage"
        />

        <AdminTable
          :columns="columns"
          :data="rows"
          :loading="status === 'pending'"
          search-placeholder="Search assets..."
          @reset="resetAssetFilters"
        >
          <template #filters>
            <USelect
              v-model="assetTypeFilter"
              :items="assetTypeOptions"
              class="min-w-40"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            />
            <USelect
              v-model="projectFilter"
              :items="projectFilterOptions"
              class="min-w-40"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            />
            <USelect
              v-model="tenantFilter"
              :items="tenantFilterOptions"
              class="min-w-40"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            />
          </template>
        </AdminTable>
      </div>

      <AdminResourceFormModal
        v-model:open="formOpen"
        v-model:project-id="selectedProjectId"
        v-model:parent-id="selectedParentId"
        resource-type="asset"
        :resource="editingAsset"
        :project-options="projectOptions"
        :parent-options="parentOptions"
        :loading="formLoading"
        @submit-asset="saveAsset"
      />

      <ConfirmDeleteModal
        v-model:open="deleteOpen"
        :title="`Delete ${deletingAsset?.name ?? 'asset'}`"
        description="This action cannot be undone. Child assets will remain as root assets."
        :loading="deleteLoading"
        @confirm="deleteSelectedAsset"
      />
    </template>
  </UDashboardPanel>
</template>
