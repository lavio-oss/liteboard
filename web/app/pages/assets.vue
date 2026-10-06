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
const { createAsset, deleteAsset, loadAssets, loadProjects, updateAsset } = useAdmin()
await loadTenants()

const tenantIds = computed(() => tenants.value.map(tenant => tenant.id))
const manageableTenantIds = computed(() => new Set(
  tenants.value
    .filter(tenant => isPlatformAdmin.value || tenant.role === 'owner' || tenant.role === 'admin')
    .map(tenant => tenant.id)
))

const { data: projectsData, status: projectsStatus, error: projectsError } = await useAsyncData(
  'liteboard-participating-projects',
  () => Promise.all(tenants.value.map(tenant => loadProjects(tenant.id, { participating: true })))
    .then(lists => lists.flat()),
  { watch: [tenantIds] }
)

const projects = computed(() => projectsData.value ?? [])
const selectedProjectId = ref<string | undefined>(undefined)
const formProjectId = ref<string | null>(null)

watch(projects, (nextProjects) => {
  if (selectedProjectId.value === undefined || !nextProjects.some(project => project.id === selectedProjectId.value)) {
    selectedProjectId.value = nextProjects[0]?.id
  }
}, { immediate: true })

const selectedProject = computed(() => projects.value.find(project => project.id === selectedProjectId.value) ?? null)

const projectSelectOptions = computed(() => projects.value.map(project => ({
  label: project.name,
  value: project.id
})))

const formProjectOptions = computed(() => selectedProject.value === null
  ? []
  : [{ label: selectedProject.value.name, value: selectedProject.value.id }])

const canWriteInSelectedProject = computed(() => {
  if (selectedProject.value === null) {
    return false
  }

  return isPlatformAdmin.value
    || manageableTenantIds.value.has(selectedProject.value.tenant_id)
    || selectedProject.value.role === 'admin'
    || selectedProject.value.role === 'engineer'
})

const canDeleteInSelectedProject = computed(() => {
  if (selectedProject.value === null) {
    return false
  }

  return isPlatformAdmin.value
    || manageableTenantIds.value.has(selectedProject.value.tenant_id)
    || selectedProject.value.role === 'admin'
})

const viewOpen = ref(false)
const viewingAsset = ref<Asset | null>(null)
const formOpen = ref(false)
const formLoading = ref(false)
const selectedParentId = ref<string | null>(null)
const editingAsset = ref<Asset | null>(null)
const deleteOpen = ref(false)
const deleteLoading = ref(false)
const deletingAsset = ref<Asset | null>(null)
const assetTypeFilter = ref<AssetType | 'all'>('all')

function resetAssetFilters(): void {
  assetTypeFilter.value = 'all'
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

const { data: assetsData, status: assetsStatus, error: assetsError, refresh: refreshAssets } = await useAsyncData(
  'liteboard-project-assets',
  async () => {
    if (selectedProject.value === null) {
      return []
    }

    return loadAssets(selectedProject.value.tenant_id, selectedProject.value.id)
  },
  { watch: [selectedProjectId] }
)

const assets = computed(() => assetsData.value ?? [])

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
    .filter(asset => !disallowedParentIds.value.has(asset.id))
    .map(asset => ({ label: asset.name, value: asset.id }))
])

const filteredAssets = computed(() => assets.value.filter((asset) => {
  const matchesType = assetTypeFilter.value === 'all' || asset.asset_type === assetTypeFilter.value

  return matchesType
}))

const rows = computed<AdminTableRow[]>(() => filteredAssets.value)

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

function openView(assetId: string): void {
  const asset = assets.value.find(item => item.id === assetId)

  if (asset === undefined) {
    throw new Error('The selected asset is not available.')
  }

  viewingAsset.value = asset
  viewOpen.value = true
}

function openCreate(): void {
  editingAsset.value = null
  formProjectId.value = selectedProject.value?.id ?? null
  selectedParentId.value = null
  formOpen.value = true
}

function openEdit(assetId: string): void {
  const asset = assets.value.find(item => item.id === assetId)

  if (asset === undefined) {
    throw new Error('The selected asset is not available.')
  }

  editingAsset.value = asset
  formProjectId.value = asset.project_id
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
  if (selectedProject.value === null) {
    toast.add({
      title: 'Project is required',
      description: 'Select a project before saving the asset.',
      color: 'error'
    })

    return
  }

  formLoading.value = true

  try {
    const tenantId = selectedProject.value.tenant_id
    const projectId = selectedProject.value.id

    if (editingAsset.value === null) {
      await createAsset(tenantId, projectId, payload)
    } else {
      await updateAsset(tenantId, projectId, editingAsset.value.id, payload)
    }

    await refreshAssets()
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
  if (selectedProject.value === null || deletingAsset.value === null) {
    throw new Error('A project and asset are required before deletion.')
  }

  deleteLoading.value = true

  try {
    await deleteAsset(
      selectedProject.value.tenant_id,
      selectedProject.value.id,
      deletingAsset.value.id
    )
    await refreshAssets()
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
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Asset type',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
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
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Status',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
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
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Created',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
    cell: ({ row }) => {
      if (row.original.created_at === undefined) {
        throw new Error('The asset created_at value is missing.')
      }

      return formatDateTime(row.original.created_at)
    }
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const buttons = [
        h(UButton, {
          'icon': 'i-lucide-eye',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `View ${row.original.name}`,
          'onClick': () => openView(row.original.id)
        })
      ]

      if (canWriteInSelectedProject.value) {
        buttons.push(
          h(UButton, {
            'icon': 'i-lucide-pencil',
            'color': 'neutral',
            'variant': 'ghost',
            'aria-label': `Edit ${row.original.name}`,
            'onClick': () => openEdit(row.original.id)
          })
        )
      }

      if (canDeleteInSelectedProject.value) {
        buttons.push(
          h(UButton, {
            'icon': 'i-lucide-trash-2',
            'color': 'error',
            'variant': 'ghost',
            'aria-label': `Delete ${row.original.name}`,
            'onClick': () => openDelete(row.original.id)
          })
        )
      }

      return h('div', { class: 'flex justify-end gap-1' }, buttons)
    }
  }
]

const errorMessage = computed(() => {
  if (projectsError.value instanceof ApiError) {
    return projectsError.value.message
  }

  if (assetsError.value instanceof ApiError) {
    return assetsError.value.message
  }

  return 'The assets could not be loaded.'
})
</script>

<template>
  <UDashboardPanel id="liteboard-assets" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Assets">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="canWriteInSelectedProject"
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
          v-if="projectsStatus === 'error' || assetsStatus === 'error'"
          color="error"
          variant="subtle"
          title="Unable to load assets"
          :description="errorMessage"
        />

        <UAlert
          v-if="projectsStatus !== 'pending' && projects.length === 0"
          color="warning"
          variant="subtle"
          title="No project assigned"
          description="Your account is not currently assigned to any project. Contact an administrator to request access."
        />

        <AdminTable
          v-else
          :columns="columns"
          :data="rows"
          :loading="projectsStatus === 'pending' || assetsStatus === 'pending'"
          search-placeholder="Search assets..."
          @reset="resetAssetFilters"
        >
          <template #search-trailing>
            <USelect
              v-if="projects.length > 1"
              v-model="selectedProjectId"
              :items="projectSelectOptions"
              class="min-w-44"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            />
          </template>

          <template #filters>
            <USelect
              v-model="assetTypeFilter"
              :items="assetTypeOptions"
              class="min-w-40"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            />
          </template>
        </AdminTable>
      </div>

      <AdminResourceFormModal
        v-if="selectedProject !== null"
        v-model:open="formOpen"
        v-model:project-id="formProjectId"
        v-model:parent-id="selectedParentId"
        resource-type="asset"
        :resource="editingAsset"
        :project-options="formProjectOptions"
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

      <AdminAssetDetailModal
        v-model:open="viewOpen"
        :asset="viewingAsset"
        :project-name="selectedProject?.name"
        :parent-name="viewingAsset ? parentNameFor(viewingAsset.parent_asset_id) : undefined"
        :can-edit="canWriteInSelectedProject"
        @edit="(asset) => openEdit(asset.id)"
      />
    </template>
  </UDashboardPanel>
</template>
