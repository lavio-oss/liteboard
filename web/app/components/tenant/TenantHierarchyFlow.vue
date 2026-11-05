<script setup lang="ts">
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { Handle, Position, VueFlow, useVueFlow } from '@vue-flow/core'
import type { Edge, Node } from '@vue-flow/core'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import type { Tenant } from '~/types/auth'
import { flattenFilterItems, layoutHierarchy, withAncestors } from '~/utils/hierarchyTree'
import type { HierarchyEntry, HierarchyFilterItem } from '~/utils/hierarchyTree'
import type { Asset, AssetInput, AssetType, Project, TenantHierarchy } from '~/types/admin'

const props = defineProps<{
  tenants: Tenant[]
  initialTenantId: string | null
}>()

type FlowKind = 'tenant' | 'project' | AssetType
type FlowItem = Tenant | Project | Asset

type FlowNodeData = {
  label: string
  kind: FlowKind
  code: string | null
  sublabel: string | null
  isLeaf: boolean
  rawItem: FlowItem
}

const kindConfig = {
  tenant: {
    label: 'Tenant',
    icon: 'i-lucide-building-2',
    iconClass: 'text-teal-500',
    badgeClass: 'bg-teal-500/10 text-teal-600 dark:text-teal-400 ring-teal-500/25',
    badgeColor: 'success' as const
  },
  project: {
    label: 'Project',
    icon: 'i-lucide-folder-kanban',
    iconClass: 'text-blue-500',
    badgeClass: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 ring-blue-500/25',
    badgeColor: 'info' as const
  },
  site: {
    label: 'Asset',
    icon: 'i-lucide-map-pin',
    iconClass: 'text-emerald-500',
    badgeClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 ring-emerald-500/25',
    badgeColor: 'success' as const
  },
  factory: {
    label: 'Asset',
    icon: 'i-lucide-building-2',
    iconClass: 'text-purple-500',
    badgeClass: 'bg-purple-500/10 text-purple-600 dark:text-purple-400 ring-purple-500/25',
    badgeColor: 'secondary' as const
  },
  area: {
    label: 'Asset',
    icon: 'i-lucide-panels-top-left',
    iconClass: 'text-primary',
    badgeClass: 'bg-primary/10 text-primary ring-primary/25',
    badgeColor: 'primary' as const
  },
  production_line: {
    label: 'Asset',
    icon: 'i-lucide-workflow',
    iconClass: 'text-amber-500',
    badgeClass: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 ring-amber-500/25',
    badgeColor: 'warning' as const
  },
  station: {
    label: 'Asset',
    icon: 'i-lucide-cuboid',
    iconClass: 'text-slate-500',
    badgeClass: 'bg-slate-500/10 text-slate-600 dark:text-slate-400 ring-slate-500/25',
    badgeColor: 'neutral' as const
  },
  equipment: {
    label: 'Asset',
    icon: 'i-lucide-cog',
    iconClass: 'text-orange-500',
    badgeClass: 'bg-orange-500/10 text-orange-600 dark:text-orange-400 ring-orange-500/25',
    badgeColor: 'error' as const
  }
} satisfies Record<FlowKind, {
  label: string
  icon: string
  iconClass: string
  badgeClass: string
  badgeColor: 'success' | 'neutral' | 'primary' | 'secondary' | 'warning' | 'error' | 'info'
}>

const assetTypeLabels: Record<AssetType, string> = {
  site: 'Site',
  factory: 'Factory',
  area: 'Area',
  production_line: 'Production Line',
  station: 'Station',
  equipment: 'Equipment'
}

function getKindConfig(kind: FlowKind) {
  return kindConfig[kind]
}

function getKindLabel(kind: FlowKind): string {
  if (kind === 'tenant' || kind === 'project') {
    return getKindConfig(kind).label
  }

  return `Asset - ${assetTypeLabels[kind]}`
}

const selectedTenantId = ref<string | null>(props.initialTenantId ?? props.tenants[0]?.id ?? null)
const tenantHierarchies = ref<Array<{ tenant: Tenant, hierarchy: TenantHierarchy }>>([])
const loading = ref(false)
const error = ref<ApiError | null>(null)
const detailsOpen = ref(false)
const selectedNode = ref<FlowNodeData | null>(null)
const assetFormOpen = ref(false)
const assetFormLoading = ref(false)
const selectedProjectId = ref<string | null>(null)
const selectedParentId = ref<string | null>(null)
const selectedFilterKeys = ref<string[]>([])
const toast = useToast()
const { createAsset, loadTenantHierarchy } = useAdmin()

const displayTenants = computed(() => props.initialTenantId === null
  ? props.tenants
  : props.tenants.filter(tenant => tenant.id === props.initialTenantId))
const hierarchyForSelectedTenant = computed(() => tenantHierarchies.value
  .find(item => item.tenant.id === selectedTenantId.value)?.hierarchy)
const allProjects = computed(() => tenantHierarchies.value.flatMap(item => item.hierarchy.projects))

const projectOptions = computed(() => (hierarchyForSelectedTenant.value?.projects ?? []).map(project => ({
  label: project.name,
  value: project.id
})))

const parentOptions = computed(() => [
  { label: 'Root asset', value: null },
  ...(hierarchyForSelectedTenant.value?.assets ?? [])
    .filter(asset => asset.project_id === selectedProjectId.value)
    .map(asset => ({ label: asset.name, value: asset.id }))
])

function buildAssetFilterItems(assets: Asset[], projectId: string, parentAssetId: string | null): HierarchyFilterItem[] {
  return assets
    .filter(asset => asset.project_id === projectId && asset.parent_asset_id === parentAssetId)
    .map(asset => ({
      id: `asset-${asset.id}`,
      label: asset.name,
      searchText: `${asset.name} ${asset.code} ${asset.location ?? ''} ${assetTypeLabels[asset.asset_type]}`,
      icon: getKindConfig(asset.asset_type).icon,
      iconClass: getKindConfig(asset.asset_type).iconClass,
      children: buildAssetFilterItems(assets, projectId, asset.id),
      onSelect: preventFilterRowSelection
    }))
}

function preventFilterRowSelection(event: { preventDefault: () => void }): void {
  event.preventDefault()
}

const filterTreeItems = computed<HierarchyFilterItem[]>(() => tenantHierarchies.value.flatMap(({ hierarchy }) =>
  hierarchy.projects.map(project => ({
    id: `project-${project.id}`,
    label: project.name,
    searchText: `${project.name} ${project.description ?? ''}`,
    icon: getKindConfig('project').icon,
    iconClass: getKindConfig('project').iconClass,
    children: buildAssetFilterItems(hierarchy.assets, project.id, null),
    onSelect: preventFilterRowSelection
  }))
))

function selectAllHierarchyFilter(): void {
  selectedFilterKeys.value = flattenFilterItems(filterTreeItems.value).map(item => item.id)
}

async function loadHierarchy(selectAfterReload?: string): Promise<void> {
  if (selectAfterReload === undefined) {
    selectedFilterKeys.value = []
    tenantHierarchies.value = []
  }

  loading.value = true
  error.value = null

  try {
    tenantHierarchies.value = await Promise.all(displayTenants.value.map(async tenant => ({
      tenant,
      hierarchy: await loadTenantHierarchy(tenant.id)
    })))
    if (selectAfterReload === undefined) {
      selectAllHierarchyFilter()
    } else {
      selectedFilterKeys.value = [...selectedFilterKeys.value, selectAfterReload]
    }
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    error.value = requestError
  } finally {
    loading.value = false
  }
}

watch(displayTenants, () => loadHierarchy(), { immediate: true })

watch(() => props.initialTenantId, (tenantId) => {
  selectedTenantId.value = tenantId ?? props.tenants[0]?.id ?? null
  selectedFilterKeys.value = []
})

watch(() => props.tenants, (tenants) => {
  if (!tenants.some(tenant => tenant.id === selectedTenantId.value)) {
    selectedTenantId.value = props.initialTenantId ?? tenants[0]?.id ?? null
    selectedFilterKeys.value = []
  }
}, { deep: true })

function openDetails(data: FlowNodeData): void {
  selectedNode.value = data
  detailsOpen.value = true
}

function isTenantItem(item: FlowItem): item is Tenant {
  return 'role' in item
}

function isProjectItem(item: FlowItem): item is Project {
  return 'description' in item
}

function isAssetItem(item: FlowItem): item is Asset {
  return 'asset_type' in item
}

function tenantForNode(node: FlowNodeData): Tenant {
  if (isTenantItem(node.rawItem)) {
    return node.rawItem
  }

  let tenantId: string

  if (isProjectItem(node.rawItem)) {
    tenantId = node.rawItem.tenant_id
  } else if (isAssetItem(node.rawItem)) {
    const asset = node.rawItem
    const project = allProjects.value.find(item => item.id === asset.project_id)

    if (project === undefined) {
      throw new Error('The asset project is not available in the loaded tenant hierarchy.')
    }

    tenantId = project.tenant_id
  } else {
    throw new Error('The node type cannot be assigned to a tenant.')
  }

  const tenant = props.tenants.find(item => item.id === tenantId)

  if (tenant === undefined) {
    throw new Error('The node tenant is not available in the loaded tenant list.')
  }

  return tenant
}

function canManageNode(node: FlowNodeData): boolean {
  const tenant = tenantForNode(node)

  return tenant.permissions.includes('asset.write')
}

function openAddAsset(node: FlowNodeData): void {
  if (!canManageNode(node)) {
    return
  }

  if (isProjectItem(node.rawItem)) {
    selectedTenantId.value = tenantForNode(node).id
    selectedProjectId.value = node.rawItem.id
    selectedParentId.value = null
  } else if (isAssetItem(node.rawItem)) {
    selectedTenantId.value = tenantForNode(node).id
    selectedProjectId.value = node.rawItem.project_id
    selectedParentId.value = node.rawItem.id
  } else {
    return
  }

  assetFormOpen.value = true
}

async function saveAsset(payload: AssetInput): Promise<void> {
  if (selectedTenantId.value === null || selectedProjectId.value === null) {
    throw new Error('A tenant and project are required before creating an asset.')
  }

  assetFormLoading.value = true

  try {
    const created = await createAsset(selectedTenantId.value, selectedProjectId.value, payload)
    await loadHierarchy(`asset-${created.id}`)
    assetFormOpen.value = false
    toast.add({
      title: 'Asset created',
      description: 'The asset has been added to the hierarchy.',
      color: 'success'
    })
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    toast.add({
      title: 'Unable to create asset',
      description: requestError.message,
      color: 'error'
    })
  } finally {
    assetFormLoading.value = false
  }
}

type DetailField = {
  label: string
  value: string | number | null
  isBadge?: boolean
  badgeColor?: 'success' | 'neutral' | 'primary' | 'secondary' | 'warning'
}

const detailFields = computed<DetailField[]>(() => {
  const node = selectedNode.value

  if (node === null) {
    return []
  }

  const fields: DetailField[] = [
    { label: 'Name', value: node.rawItem.name },
    {
      label: 'Status',
      value: node.rawItem.status,
      isBadge: true,
      badgeColor: node.rawItem.status === 'active' ? 'success' : 'neutral'
    }
  ]

  if (isTenantItem(node.rawItem)) {
    fields.push({ label: 'Code', value: node.rawItem.code })

    fields.push({
      label: 'Your role',
      value: node.rawItem.role.name,
      isBadge: true,
      badgeColor: node.rawItem.role.is_owner ? 'primary' : 'neutral'
    })
  }

  if (isProjectItem(node.rawItem)) {
    if (node.rawItem.description !== null) {
      fields.push({ label: 'Description', value: node.rawItem.description })
    }
  }

  if (isAssetItem(node.rawItem)) {
    fields.push({ label: 'Code', value: node.rawItem.code })

    if (node.rawItem.location !== null) {
      fields.push({ label: 'Location', value: node.rawItem.location })
    }
  }

  if ('created_at' in node.rawItem && 'updated_at' in node.rawItem) {
    fields.push(
      { label: 'Created at', value: formatDateTime(node.rawItem.created_at) },
      { label: 'Updated at', value: formatDateTime(node.rawItem.updated_at) }
    )
  }

  return fields
})

const hierarchyEntries = computed<HierarchyEntry[]>(() => tenantHierarchies.value.flatMap(({ hierarchy }) => [
  ...hierarchy.projects.map(project => ({ id: `project-${project.id}`, parentId: null })),
  ...hierarchy.assets.map(asset => ({
    id: `asset-${asset.id}`,
    parentId: asset.parent_asset_id === null ? `project-${asset.project_id}` : `asset-${asset.parent_asset_id}`
  }))
]))

const nodeDataById = computed(() => {
  const byId = new Map<string, Omit<FlowNodeData, 'isLeaf'>>()

  for (const { hierarchy } of tenantHierarchies.value) {
    for (const project of hierarchy.projects) {
      byId.set(`project-${project.id}`, { kind: 'project', label: project.name, code: null, sublabel: project.description, rawItem: project })
    }

    for (const asset of hierarchy.assets) {
      byId.set(`asset-${asset.id}`, { kind: asset.asset_type, label: asset.name, code: asset.code, sublabel: asset.location, rawItem: asset })
    }
  }

  return byId
})

const selectedKeySet = computed(() => new Set(selectedFilterKeys.value))

const visibleLayout = computed(() => {
  const visible = withAncestors(selectedFilterKeys.value, hierarchyEntries.value)

  return { visible, ...layoutHierarchy(hierarchyEntries.value, visible) }
})

const visibleNodes = computed<Node<FlowNodeData>[]>(() => {
  const { visible, positions, childrenOf } = visibleLayout.value

  return hierarchyEntries.value.flatMap(({ id }) => {
    const data = nodeDataById.value.get(id)
    const position = positions.get(id)

    if (!visible.has(id) || data === undefined || position === undefined) {
      return []
    }

    return [{
      id,
      type: 'hierarchy',
      position: { x: Math.round(position.x), y: position.y },
      data: { ...data, isLeaf: !childrenOf.has(id) }
    }]
  })
})

const visibleEdges = computed<Edge[]>(() => [...visibleLayout.value.childrenOf].flatMap(([source, targets]) =>
  targets.map(target => ({
    id: `${source}->${target}`,
    source,
    target,
    type: 'smoothstep',
    style: { stroke: 'var(--ui-primary, #2563eb)', strokeWidth: 2 }
  }))
))

const { fitView, onNodesInitialized } = useVueFlow('iiot-tree')

onNodesInitialized(() => fitView({ padding: 0.2, duration: 200 }))

watch(visibleNodes, () => {
  setTimeout(() => fitView({ padding: 0.2, duration: 200 }), 120)
}, { flush: 'post' })
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-3">
      <TenantHierarchyFilterTree v-model:selected-keys="selectedFilterKeys" :items="filterTreeItems" />
    </div>

    <UAlert
      v-if="error !== null"
      color="error"
      variant="subtle"
      title="Unable to load tenant hierarchy"
      :description="error.message"
    />

    <div
      v-if="loading"
      class="flex h-[620px] items-center justify-center rounded-xl border border-default bg-elevated/20 text-sm text-muted"
    >
      Loading IIoT hierarchy...
    </div>

    <div
      v-else-if="displayTenants.length === 0"
      class="flex h-[620px] items-center justify-center rounded-xl border border-default bg-elevated/20 text-sm text-muted"
    >
      No tenant is available for the current account.
    </div>

    <ClientOnly v-else>
      <div class="relative h-[620px] overflow-hidden rounded-xl border border-default bg-elevated/20">
        <div
          v-if="visibleNodes.length === 0"
          class="flex h-full items-center justify-center text-sm text-muted"
        >
          No hierarchy nodes selected. Select nodes from the filter above to display.
        </div>
        <VueFlow
          v-else
          id="iiot-tree"
          :nodes="visibleNodes"
          :edges="visibleEdges"
          :fit-view-on-init="true"
          :nodes-draggable="false"
          :elements-selectable="false"
          :min-zoom="0.1"
          :max-zoom="2"
          @node-click="({ node }) => openDetails(node.data)"
        >
          <Background pattern-color="#64748b" :gap="24" />
          <Controls position="bottom-left" :fit-view-params="{ padding: 0.2 }" />

          <template #node-hierarchy="{ id, data }">
            <div
              class="relative flex w-60 cursor-pointer flex-col justify-between rounded-md border border-default bg-elevated/95 p-3 shadow-xs transition-all hover:border-primary/60 hover:shadow-md"
              :class="selectedKeySet.has(id) ? 'border-primary ring-2 ring-primary/40 shadow-md' : ''"
              @click="openDetails(data)"
            >
              <Handle
                v-if="data.kind !== 'project'"
                type="target"
                :position="Position.Top"
                class="!size-2 !border-2 !border-default !bg-muted"
              />

              <div class="flex items-center justify-between gap-1.5 mb-1.5">
                <div class="flex items-center gap-1.5 min-w-0">
                  <UIcon
                    :name="getKindConfig(data.kind).icon"
                    class="size-3.5 shrink-0"
                    :class="getKindConfig(data.kind).iconClass"
                  />
                  <span class="text-[10px] font-bold tracking-wider uppercase text-muted truncate">
                    {{ getKindLabel(data.kind) }}
                  </span>
                </div>

                <div class="flex items-center gap-1">
                  <UButton
                    icon="i-lucide-info"
                    color="neutral"
                    variant="ghost"
                    size="xs"
                    class="-my-1 text-muted hover:text-highlighted cursor-pointer"
                    aria-label="View details"
                    @click.stop="openDetails(data)"
                    @pointerdown.stop
                    @mousedown.stop
                  />
                  <UButton
                    v-if="canManageNode(data) && (data.kind === 'project' || isAssetItem(data.rawItem))"
                    icon="i-lucide-plus"
                    color="primary"
                    variant="ghost"
                    size="xs"
                    class="-my-1 text-muted hover:text-primary cursor-pointer"
                    :aria-label="`Add asset under ${data.label}`"
                    @click.stop="openAddAsset(data)"
                    @pointerdown.stop
                    @mousedown.stop
                  />
                </div>
              </div>

              <div class="text-sm font-semibold text-highlighted truncate" :title="data.label">
                {{ data.label }}
              </div>

              <div
                v-if="data.code !== null || data.sublabel !== null"
                class="flex items-center gap-1.5 text-xs text-muted truncate mt-1.5"
              >
                <span
                  v-if="data.code !== null"
                  class="bg-elevated py-0.5 text-[11px] font-medium"
                >
                  {{ data.code }}
                </span>
                <span v-if="data.code !== null && data.sublabel !== null" class="text-muted/60">•</span>
                <span v-if="data.sublabel !== null" class="truncate text-[11px]">
                  {{ data.sublabel }}
                </span>
              </div>

              <Handle
                v-if="!data.isLeaf"
                type="source"
                :position="Position.Bottom"
                class="!size-2 !border-2 !border-default !bg-muted"
              />
            </div>
          </template>
        </VueFlow>
      </div>

      <template #fallback>
        <div class="flex h-[620px] items-center justify-center rounded-xl border border-default bg-elevated/20 text-sm text-muted">
          Preparing IIoT hierarchy...
        </div>
      </template>
    </ClientOnly>
  </div>

  <UModal
    v-model:open="detailsOpen"
    :title="selectedNode ? `${getKindLabel(selectedNode.kind)} Details` : 'Details'"
  >
    <template #body>
      <div v-if="selectedNode" class="space-y-4">
        <div class="flex items-center gap-2.5 p-3 rounded-lg bg-elevated/50 border border-default">
          <UIcon
            :name="getKindConfig(selectedNode.kind).icon"
            class="size-6 shrink-0"
            :class="getKindConfig(selectedNode.kind).iconClass"
          />
          <div class="min-w-0 flex-1">
            <div class="text-xs uppercase font-bold tracking-wider text-muted">
              {{ getKindLabel(selectedNode.kind) }}
            </div>
            <div class="text-base font-semibold text-highlighted truncate">
              {{ selectedNode.label }}
            </div>
          </div>
        </div>

        <dl class="divide-y divide-default border border-default rounded-lg overflow-hidden text-sm">
          <div
            v-for="field in detailFields"
            :key="field.label"
            class="flex items-center justify-between px-4 py-2.5 bg-elevated/20 even:bg-transparent"
          >
            <dt class="text-muted font-medium">
              {{ field.label }}
            </dt>
            <dd class="text-highlighted font-medium text-right">
              <UBadge
                v-if="field.isBadge"
                :color="field.badgeColor ?? 'neutral'"
                variant="subtle"
                class="capitalize"
              >
                {{ field.value }}
              </UBadge>
              <span v-else>{{ field.value ?? '—' }}</span>
            </dd>
          </div>
        </dl>

        <div class="flex justify-end pt-2">
          <UButton
            label="Close"
            color="neutral"
            variant="subtle"
            @click="detailsOpen = false"
          />
        </div>
      </div>
    </template>
  </UModal>

  <AdminResourceFormModal
    v-model:open="assetFormOpen"
    v-model:project-id="selectedProjectId"
    v-model:parent-id="selectedParentId"
    resource-type="asset"
    :project-options="projectOptions"
    :parent-options="parentOptions"
    :loading="assetFormLoading"
    @submit-asset="saveAsset"
  />
</template>
