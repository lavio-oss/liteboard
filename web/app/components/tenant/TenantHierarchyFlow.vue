<script setup lang="ts">
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { Handle, Position, VueFlow } from '@vue-flow/core'
import type { Edge, Node } from '@vue-flow/core'
import { ApiError } from '~/composables/useApi'
import { useMasterdata } from '~/composables/useMasterdata'
import type { Tenant } from '~/types/auth'
import type { Asset, AssetInput, AssetType, Project, TenantHierarchy } from '~/types/masterdata'

const props = defineProps<{
  tenants: Tenant[]
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

type TreeNode = {
  id: string
  label: string
  kind: FlowKind
  code: string | null
  sublabel: string | null
  rawItem: FlowItem
  children: TreeNode[]
  x: number
  y: number
  subtreeWidth: number
}

const kindConfig = {
  tenant: {
    label: 'Tenant',
    icon: 'i-lucide-building-2',
    iconClass: 'text-emerald-500',
    badgeColor: 'success' as const
  },
  project: {
    label: 'Project',
    icon: 'i-lucide-folder-kanban',
    iconClass: 'text-blue-500',
    badgeColor: 'primary' as const
  },
  site: {
    label: 'Asset',
    icon: 'i-lucide-box',
    iconClass: 'text-indigo-500',
    badgeColor: 'secondary' as const
  },
  factory: {
    label: 'Asset',
    icon: 'i-lucide-box',
    iconClass: 'text-indigo-500',
    badgeColor: 'secondary' as const
  },
  area: {
    label: 'Asset',
    icon: 'i-lucide-box',
    iconClass: 'text-indigo-500',
    badgeColor: 'secondary' as const
  },
  production_line: {
    label: 'Asset',
    icon: 'i-lucide-box',
    iconClass: 'text-indigo-500',
    badgeColor: 'secondary' as const
  },
  station: {
    label: 'Asset',
    icon: 'i-lucide-box',
    iconClass: 'text-indigo-500',
    badgeColor: 'secondary' as const
  }
} satisfies Record<FlowKind, {
  label: string
  icon: string
  iconClass: string
  badgeColor: 'success' | 'neutral' | 'primary' | 'secondary' | 'warning'
}>

const assetTypeLabels: Record<AssetType, string> = {
  site: 'Site',
  factory: 'Factory',
  area: 'Area',
  production_line: 'Production Line',
  station: 'Station'
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

const selectedTenantId = ref<number | null>(props.tenants[0]?.id ?? null)
const hierarchy = ref<TenantHierarchy | null>(null)
const loading = ref(false)
const error = ref<ApiError | null>(null)
const detailsOpen = ref(false)
const selectedNode = ref<FlowNodeData | null>(null)
const assetFormOpen = ref(false)
const assetFormLoading = ref(false)
const selectedProjectId = ref<number | null>(null)
const selectedParentId = ref<number | null>(null)
const toast = useToast()
const { user } = useAuth()
const { createAsset, loadTenantHierarchy } = useMasterdata()

const tenantOptions = computed(() => props.tenants.map(tenant => ({
  label: `${tenant.name} (${tenant.code})`,
  value: tenant.id
})))

const selectedTenant = computed(() => props.tenants.find(tenant => tenant.id === selectedTenantId.value))
const canManageSelectedTenant = computed(() => {
  const tenant = selectedTenant.value

  return user.value?.system_role === 'platform_admin'
    || tenant?.role === 'owner'
    || tenant?.role === 'admin'
})

const projectOptions = computed(() => (hierarchy.value?.projects ?? []).map(project => ({
  label: project.name,
  value: project.id
})))

const parentOptions = computed(() => [
  { label: 'Root asset', value: null },
  ...(hierarchy.value?.assets ?? [])
    .filter(asset => asset.project_id === selectedProjectId.value)
    .map(asset => ({ label: `${asset.name} (${asset.code})`, value: asset.id }))
])

async function loadHierarchy(): Promise<void> {
  if (selectedTenantId.value === null) {
    hierarchy.value = null

    return
  }

  loading.value = true
  error.value = null

  try {
    hierarchy.value = await loadTenantHierarchy(selectedTenantId.value)
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    error.value = requestError
  } finally {
    loading.value = false
  }
}

watch(selectedTenantId, loadHierarchy, { immediate: true })

watch(
  () => props.tenants,
  (tenants) => {
    if (!tenants.some(tenant => tenant.id === selectedTenantId.value)) {
      selectedTenantId.value = tenants[0]?.id ?? null
    }
  },
  { deep: true }
)

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

function openAddAsset(node: FlowNodeData): void {
  if (!canManageSelectedTenant.value) {
    return
  }

  if (isProjectItem(node.rawItem)) {
    selectedProjectId.value = node.rawItem.id
    selectedParentId.value = null
  } else if (isAssetItem(node.rawItem)) {
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
    await createAsset(selectedTenantId.value, selectedProjectId.value, payload)
    await loadHierarchy()
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

    if (node.rawItem.role !== null) {
      fields.push({
        label: 'Your role',
        value: node.rawItem.role,
        isBadge: true,
        badgeColor: node.rawItem.role === 'owner' ? 'primary' : 'neutral'
      })
    }
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

const CARD_WIDTH = 250
const CARD_GAP = 32

const nodes = computed<Node<FlowNodeData>[]>(() => {
  const currentTenant = selectedTenant.value
  const currentHierarchy = hierarchy.value

  if (currentTenant === undefined || currentHierarchy === null) {
    return []
  }

  const assets = currentHierarchy.assets

  function buildAssetChildren(projectId: number, parentAssetId: number | null, y: number): TreeNode[] {
    return assets
      .filter(asset => asset.project_id === projectId && asset.parent_asset_id === parentAssetId)
      .map((asset) => {
        const children = buildAssetChildren(projectId, asset.id, y + 140)
        const childrenTotalWidth = children.length > 0
          ? children.reduce((sum, child) => sum + child.subtreeWidth, 0) + (children.length - 1) * CARD_GAP
          : CARD_WIDTH

        return {
          id: `asset-${asset.id}`,
          label: asset.name,
          kind: asset.asset_type,
          code: asset.code,
          sublabel: asset.location,
          rawItem: asset,
          children,
          x: 0,
          y,
          subtreeWidth: Math.max(CARD_WIDTH, childrenTotalWidth)
        }
      })
  }

  function buildProjectTree(project: Project): TreeNode {
    const assetChildren = buildAssetChildren(project.id, null, 320)
    const assetsTotalWidth = assetChildren.length > 0
      ? assetChildren.reduce((sum, child) => sum + child.subtreeWidth, 0) + (assetChildren.length - 1) * CARD_GAP
      : CARD_WIDTH

    return {
      id: `project-${project.id}`,
      label: project.name,
      kind: 'project',
      code: null,
      sublabel: project.description,
      rawItem: project,
      children: assetChildren,
      x: 0,
      y: 180,
      subtreeWidth: Math.max(CARD_WIDTH, assetsTotalWidth)
    }
  }

  const projectNodes = currentHierarchy.projects.map(buildProjectTree)
  const projectsTotalWidth = projectNodes.length > 0
    ? projectNodes.reduce((sum, child) => sum + child.subtreeWidth, 0) + (projectNodes.length - 1) * CARD_GAP
    : CARD_WIDTH

  const tenantNode: TreeNode = {
    id: `tenant-${currentTenant.id}`,
    label: currentTenant.name,
    kind: 'tenant',
    code: currentTenant.code,
    sublabel: null,
    rawItem: currentTenant,
    children: projectNodes,
    x: 0,
    y: 40,
    subtreeWidth: Math.max(CARD_WIDTH, projectsTotalWidth)
  }

  function layoutSubtree(node: TreeNode, leftX: number): void {
    if (node.children.length === 0) {
      node.x = leftX + (node.subtreeWidth - CARD_WIDTH) / 2

      return
    }

    const childrenWidth = node.children.reduce((sum, child) => sum + child.subtreeWidth, 0)
      + (node.children.length - 1) * CARD_GAP
    let currentChildLeftX = leftX + (node.subtreeWidth - childrenWidth) / 2

    for (const child of node.children) {
      layoutSubtree(child, currentChildLeftX)
      currentChildLeftX += child.subtreeWidth + CARD_GAP
    }

    const firstChild = node.children[0]
    const lastChild = node.children[node.children.length - 1]

    if (firstChild !== undefined && lastChild !== undefined) {
      const firstChildCenter = firstChild.x + CARD_WIDTH / 2
      const lastChildCenter = lastChild.x + CARD_WIDTH / 2
      node.x = (firstChildCenter + lastChildCenter) / 2 - CARD_WIDTH / 2
    }
  }

  const startLeftX = Math.max(80, 600 - tenantNode.subtreeWidth / 2)
  layoutSubtree(tenantNode, startLeftX)

  const flowNodes: Node<FlowNodeData>[] = []

  function collectNodes(node: TreeNode): void {
    flowNodes.push({
      id: node.id,
      type: 'hierarchy',
      position: { x: Math.round(node.x), y: node.y },
      data: {
        kind: node.kind,
        label: node.label,
        code: node.code,
        sublabel: node.sublabel,
        isLeaf: node.children.length === 0,
        rawItem: node.rawItem
      }
    })

    for (const child of node.children) {
      collectNodes(child)
    }
  }

  collectNodes(tenantNode)

  return flowNodes
})

const edges = computed<Edge[]>(() => {
  const currentTenant = selectedTenant.value
  const currentHierarchy = hierarchy.value

  if (currentTenant === undefined || currentHierarchy === null) {
    return []
  }

  const result: Edge[] = []

  currentHierarchy.projects.forEach((project) => {
    result.push({
      id: `tenant-${currentTenant.id}-project-${project.id}`,
      source: `tenant-${currentTenant.id}`,
      target: `project-${project.id}`,
      type: 'smoothstep',
      animated: true,
      style: { stroke: 'var(--ui-border-accented, #94a3b8)', strokeWidth: 1.5 }
    })
  })

  currentHierarchy.assets.forEach((asset) => {
    const source = asset.parent_asset_id === null
      ? `project-${asset.project_id}`
      : `asset-${asset.parent_asset_id}`

    result.push({
      id: `${source}-asset-${asset.id}`,
      source,
      target: `asset-${asset.id}`,
      type: 'smoothstep',
      animated: true,
      style: { stroke: 'var(--ui-border-accented, #94a3b8)', strokeWidth: 1.5 }
    })
  })

  return result
})
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <span class="text-sm font-medium text-highlighted">Select tenant</span>
        <USelect
          :model-value="selectedTenantId ?? undefined"
          :items="tenantOptions"
          class="min-w-64"
          placeholder="Select tenant"
          @update:model-value="selectedTenantId = $event ?? null"
        />
      </div>

      <div class="flex flex-wrap items-center gap-2 text-xs text-muted">
        <UBadge color="success" variant="subtle" class="gap-1">
          <UIcon name="i-lucide-building-2" class="size-3" />
          Tenant
        </UBadge>
        <UBadge color="primary" variant="subtle" class="gap-1">
          <UIcon name="i-lucide-folder-kanban" class="size-3" />
          Project
        </UBadge>
        <UBadge color="secondary" variant="subtle" class="gap-1">
          <UIcon name="i-lucide-box" class="size-3" />
          Asset
        </UBadge>
      </div>
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
      v-else-if="selectedTenant === undefined"
      class="flex h-[620px] items-center justify-center rounded-xl border border-default bg-elevated/20 text-sm text-muted"
    >
      No tenant is available for the current account.
    </div>

    <ClientOnly v-else>
      <div class="h-[620px] overflow-hidden rounded-xl border border-default bg-elevated/20">
        <VueFlow
          :nodes="nodes"
          :edges="edges"
          :fit-view-on-init="true"
          :nodes-draggable="false"
          :elements-selectable="false"
          :min-zoom="0.2"
          :max-zoom="1.5"
          @node-click="({ node }) => openDetails(node.data)"
        >
          <Background pattern-color="#64748b" :gap="24" />
          <Controls position="bottom-left" />

          <template #node-hierarchy="{ data }">
            <div
              class="relative flex flex-col justify-between w-60 rounded-md border border-default bg-elevated/95 p-3 shadow-xs transition-all hover:border-primary/60 hover:shadow-md cursor-pointer"
              @click="openDetails(data)"
            >
              <Handle
                v-if="data.kind !== 'tenant'"
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
                    v-if="canManageSelectedTenant && (data.kind === 'project' || isAssetItem(data.rawItem))"
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
    :description="selectedNode ? `Detailed information for ${selectedNode.label}.` : undefined"
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

  <MasterdataFormModal
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
