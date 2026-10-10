<script setup lang="ts">
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { Handle, Position, VueFlow } from '@vue-flow/core'
import type { Edge, Node } from '@vue-flow/core'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import type { Tenant } from '~/types/auth'
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

type FilterTreeItem = {
  id: string
  label: string
  searchText?: string
  icon?: string
  iconClass?: string
  children?: FilterTreeItem[]
  onSelect?: (event: FilterTreeSelectEvent) => void
}

type FilterTreeSelectEvent = {
  preventDefault: () => void
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

const legendKinds: FlowKind[] = [
  'tenant',
  'project',
  'site',
  'factory',
  'area',
  'production_line',
  'station',
  'equipment'
]

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
const filterOpen = ref(false)
const filterQuery = ref('')
const selectedFilterKeys = ref<string[]>([])
const expandedFilterKeys = ref<string[]>([])
const toast = useToast()
const { user } = useAuth()
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

function buildAssetFilterItems(assets: Asset[], projectId: string, parentAssetId: string | null): FilterTreeItem[] {
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

function preventFilterRowSelection(event: FilterTreeSelectEvent): void {
  event.preventDefault()
}

const filterTreeItems = computed<FilterTreeItem[]>(() => {
  return tenantHierarchies.value.map(({ tenant, hierarchy: currentHierarchy }) => ({
    id: `tenant-${tenant.id}`,
    label: tenant.name,
    searchText: `${tenant.name} ${tenant.code}`,
    icon: getKindConfig('tenant').icon,
    iconClass: getKindConfig('tenant').iconClass,
    onSelect: preventFilterRowSelection,
    children: currentHierarchy.projects.map(project => ({
      id: `project-${project.id}`,
      label: project.name,
      searchText: `${project.name} ${project.description ?? ''}`,
      icon: getKindConfig('project').icon,
      iconClass: getKindConfig('project').iconClass,
      children: buildAssetFilterItems(currentHierarchy.assets, project.id, null),
      onSelect: preventFilterRowSelection
    }))
  }))
})

function filterTreeItemsByQuery(items: FilterTreeItem[], query: string): FilterTreeItem[] {
  const normalizedQuery = query.trim().toLocaleLowerCase()

  if (normalizedQuery === '') {
    return items
  }

  return items.flatMap((item) => {
    const children = filterTreeItemsByQuery(item.children ?? [], normalizedQuery)
    const searchText = (item.searchText ?? item.label).toLocaleLowerCase()

    if (searchText.includes(normalizedQuery)) {
      return [item]
    }

    if (children.length === 0) {
      return []
    }

    return [{ ...item, children }]
  })
}

const filteredFilterTreeItems = computed(() => filterTreeItemsByQuery(filterTreeItems.value, filterQuery.value))

function flattenFilterTreeItems(items: FilterTreeItem[]): FilterTreeItem[] {
  return items.flatMap(item => [item, ...flattenFilterTreeItems(item.children ?? [])])
}

const selectedFilterItems = computed(() => flattenFilterTreeItems(filterTreeItems.value)
  .filter(item => selectedFilterKeys.value.includes(item.id)))

const topLevelSelectedItems = computed(() => {
  const result: FilterTreeItem[] = []
  const selectedKeys = new Set(selectedFilterKeys.value)

  function traverse(item: FilterTreeItem): void {
    if (selectedKeys.has(item.id)) {
      result.push(item)

      return
    }

    for (const child of item.children ?? []) {
      traverse(child)
    }
  }

  for (const item of filterTreeItems.value) {
    traverse(item)
  }

  return result
})

const filterInputPlaceholder = computed(() => {
  if (selectedFilterKeys.value.length === 0) {
    return 'Search nodes, code, or hierarchy...'
  }

  const items = topLevelSelectedItems.value

  if (items.length === 0) {
    return 'Search nodes, code, or hierarchy...'
  }

  return items.map(item => item.label).join(', ')
})

function getSelectedFilterPathKeys(items: FilterTreeItem[]): string[] {
  const selectedKeys = new Set(selectedFilterKeys.value)
  const expandedKeys = new Set<string>()

  function hasSelectedItem(item: FilterTreeItem, ancestorKeys: string[]): boolean {
    const itemSelected = selectedKeys.has(item.id)
    let childSelected = false

    for (const child of item.children ?? []) {
      if (hasSelectedItem(child, [...ancestorKeys, item.id])) {
        childSelected = true
      }
    }

    if (itemSelected || childSelected) {
      ancestorKeys.forEach(key => expandedKeys.add(key))

      if (itemSelected && item.children?.length) {
        expandedKeys.add(item.id)
      }
    }

    return itemSelected || childSelected
  }

  items.forEach(item => hasSelectedItem(item, []))

  return [...expandedKeys]
}

function getExpandableFilterKeys(items: FilterTreeItem[]): string[] {
  return items.flatMap(item => item.children?.length
    ? [item.id, ...getExpandableFilterKeys(item.children)]
    : [])
}

function getSubtreeItemIds(item: FilterTreeItem): string[] {
  const ids = [item.id]

  if (item.children?.length) {
    for (const child of item.children) {
      ids.push(...getSubtreeItemIds(child))
    }
  }

  return ids
}

function toggleFilterNode(item: FilterTreeItem): void {
  const tenantHierarchy = tenantHierarchies.value.find(({ tenant, hierarchy }) =>
    item.id === `tenant-${tenant.id}`
    || hierarchy.projects.some(project => item.id === `project-${project.id}`)
    || hierarchy.assets.some(asset => item.id === `asset-${asset.id}`))

  if (tenantHierarchy === undefined) {
    throw new Error('The selected hierarchy node does not belong to a loaded tenant.')
  }

  if (selectedTenantId.value !== tenantHierarchy.tenant.id) {
    selectedFilterKeys.value = []
    selectedTenantId.value = tenantHierarchy.tenant.id
  }

  const subtreeIds = getSubtreeItemIds(item)
  const isFullySelected = subtreeIds.every(id => selectedFilterKeys.value.includes(id))

  if (isFullySelected) {
    const removeSet = new Set(subtreeIds)
    selectedFilterKeys.value = selectedFilterKeys.value.filter(id => !removeSet.has(id))
  } else {
    const combined = new Set([...selectedFilterKeys.value, ...subtreeIds])
    selectedFilterKeys.value = Array.from(combined)
  }
}

function selectAllHierarchyFilter(): void {
  selectedFilterKeys.value = flattenFilterTreeItems(filterTreeItems.value).map(item => item.id)
}

function clearHierarchyFilter(): void {
  selectedFilterKeys.value = []
  filterQuery.value = ''
  expandedFilterKeys.value = []
}

async function loadHierarchy(): Promise<void> {
  clearHierarchyFilter()
  filterOpen.value = false
  tenantHierarchies.value = []

  loading.value = true
  error.value = null

  try {
    tenantHierarchies.value = await Promise.all(displayTenants.value.map(async tenant => ({
      tenant,
      hierarchy: await loadTenantHierarchy(tenant.id)
    })))
    selectAllHierarchyFilter()
  } catch (requestError: unknown) {
    if (!(requestError instanceof ApiError)) {
      throw requestError
    }

    error.value = requestError
  } finally {
    loading.value = false
  }
}

watch(displayTenants, loadHierarchy, { immediate: true, deep: true })

watch(filterOpen, (open) => {
  filterQuery.value = ''

  if (open) {
    expandedFilterKeys.value = getSelectedFilterPathKeys(filterTreeItems.value)
  }
})

watch(filterQuery, (query) => {
  if (!filterOpen.value) {
    return
  }

  expandedFilterKeys.value = query === ''
    ? getSelectedFilterPathKeys(filterTreeItems.value)
    : getExpandableFilterKeys(filteredFilterTreeItems.value)
})

watch(() => props.initialTenantId, (tenantId) => {
  selectedTenantId.value = tenantId ?? props.tenants[0]?.id ?? null
  clearHierarchyFilter()
})

watch(() => props.tenants, (tenants) => {
  if (!tenants.some(tenant => tenant.id === selectedTenantId.value)) {
    selectedTenantId.value = props.initialTenantId ?? tenants[0]?.id ?? null
    clearHierarchyFilter()
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

  return user.value?.system_role === 'platform_admin'
    || tenant.role === 'owner'
    || tenant.role === 'admin'
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
  const tenantNodes = tenantHierarchies.value
    .filter(item => item.tenant.id === selectedTenantId.value)
    .map<TreeNode>(({ tenant, hierarchy: currentHierarchy }): TreeNode => {
      const assets = currentHierarchy.assets

      function buildAssetChildren(projectId: string, parentAssetId: string | null, y: number): TreeNode[] {
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

      return {
        id: `tenant-${tenant.id}`,
        label: tenant.name,
        kind: 'tenant',
        code: tenant.code,
        sublabel: null,
        rawItem: tenant,
        children: projectNodes,
        x: 0,
        y: 40,
        subtreeWidth: Math.max(CARD_WIDTH, projectsTotalWidth)
      }
    })

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

  let currentLeftX = 80

  for (const tenantNode of tenantNodes) {
    layoutSubtree(tenantNode, currentLeftX)
    currentLeftX += tenantNode.subtreeWidth + CARD_GAP * 2
  }

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

  tenantNodes.forEach(collectNodes)

  return flowNodes
})

const edges = computed<Edge[]>(() => {
  return tenantHierarchies.value
    .filter(item => item.tenant.id === selectedTenantId.value)
    .flatMap(({ tenant, hierarchy: currentHierarchy }) => {
      const tenantEdges: Edge[] = currentHierarchy.projects.map(project => ({
        id: `tenant-${tenant.id}-project-${project.id}`,
        source: `tenant-${tenant.id}`,
        target: `project-${project.id}`,
        type: 'smoothstep',
        animated: true,
        style: { stroke: 'var(--ui-border-accented, #94a3b8)', strokeWidth: 1.5 }
      }))

      currentHierarchy.assets.forEach((asset) => {
        const source = asset.parent_asset_id === null
          ? `project-${asset.project_id}`
          : `asset-${asset.parent_asset_id}`

        tenantEdges.push({
          id: `${source}-asset-${asset.id}`,
          source,
          target: `asset-${asset.id}`,
          type: 'smoothstep',
          animated: true,
          style: { stroke: 'var(--ui-border-accented, #94a3b8)', strokeWidth: 1.5 }
        })
      })

      return tenantEdges
    })
})

const visibleNodeIds = computed<Set<string>>(() => {
  return new Set(selectedFilterKeys.value)
})

const visibleNodes = computed(() => {
  const ids = visibleNodeIds.value

  if (ids === null) {
    return nodes.value
  }

  const visible = nodes.value
    .filter(node => ids.has(node.id))
    .map(node => ({ ...node, position: { ...node.position } }))
  const nodeById = new Map(visible.map(node => [node.id, node]))
  const childrenById = new Map<string, string[]>()

  for (const edge of edges.value) {
    if (!ids.has(edge.source) || !ids.has(edge.target)) {
      continue
    }

    const children = childrenById.get(edge.source) ?? []
    children.push(edge.target)
    childrenById.set(edge.source, children)
  }

  function getSubtreeWidth(nodeId: string): number {
    const children = childrenById.get(nodeId) ?? []

    return children.length === 0
      ? CARD_WIDTH
      : Math.max(
          CARD_WIDTH,
          children.reduce((total, childId) => total + getSubtreeWidth(childId), 0)
          + (children.length - 1) * CARD_GAP
        )
  }

  function layoutVisibleSubtree(nodeId: string, leftX: number): void {
    const node = nodeById.get(nodeId)

    if (node === undefined) {
      return
    }

    const children = childrenById.get(nodeId) ?? []
    const subtreeWidth = getSubtreeWidth(nodeId)

    if (children.length === 0) {
      node.position = { ...node.position, x: leftX + (subtreeWidth - CARD_WIDTH) / 2 }

      return
    }

    const childrenWidth = children.reduce((total, childId) => total + getSubtreeWidth(childId), 0)
      + (children.length - 1) * CARD_GAP
    let childLeftX = leftX + (subtreeWidth - childrenWidth) / 2

    for (const childId of children) {
      layoutVisibleSubtree(childId, childLeftX)
      childLeftX += getSubtreeWidth(childId) + CARD_GAP
    }

    const firstChild = nodeById.get(children[0]!)
    const lastChild = nodeById.get(children[children.length - 1]!)

    if (firstChild !== undefined && lastChild !== undefined) {
      node.position = {
        ...node.position,
        x: (firstChild.position.x + lastChild.position.x) / 2
      }
    }
  }

  const rootNodes = visible.filter(node => !edges.value.some(edge => edge.target === node.id && ids.has(edge.source)))

  let currentLeftX = 80
  for (const rootNode of rootNodes) {
    const subtreeWidth = getSubtreeWidth(rootNode.id)
    layoutVisibleSubtree(rootNode.id, currentLeftX)
    currentLeftX += subtreeWidth + CARD_GAP * 2
  }

  return visible
})

const visibleEdges = computed<Edge[]>(() => {
  const ids = visibleNodeIds.value

  return edges.value
    .filter(edge => ids.has(edge.source) && ids.has(edge.target))
    .map(edge => ({
      ...edge,
      class: 'iiot-highlight-edge',
      animated: true,
      style: {
        stroke: 'var(--ui-primary, #2563eb)',
        strokeWidth: 2.5
      }
    }))
})

const flowKey = computed(() => `${selectedTenantId.value}-${selectedFilterKeys.value.join('|')}`)
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-3">
      <UPopover v-model:open="filterOpen">
        <div class="inline-flex min-w-80">
          <UInput
            v-model="filterQuery"
            icon="i-lucide-search"
            class="w-full"
            :ui="{ base: 'pe-20 truncate placeholder:truncate' }"
            :placeholder="filterInputPlaceholder"
            :title="selectedFilterKeys.length > 0 ? filterInputPlaceholder : undefined"
            aria-label="Filter hierarchy"
            @click.stop="filterOpen = true"
            @focus="filterOpen = true"
            @keydown.escape="filterOpen = false"
          >
            <template #trailing>
              <UBadge
                v-if="selectedFilterKeys.length > 0"
                color="primary"
                variant="subtle"
                class="mr-1"
              >
                {{ selectedFilterKeys.length }}
              </UBadge>
              <UIcon
                name="i-lucide-chevron-down"
                class="size-4 cursor-pointer text-dimmed transition-transform"
                :class="filterOpen ? 'rotate-180' : ''"
                @click.stop="filterOpen = !filterOpen"
              />
            </template>
          </UInput>
        </div>

        <template #content>
          <div class="w-[min(32rem,calc(100vw-2rem))] p-3">
            <div class="flex items-center justify-between gap-2">
              <p class="text-xs text-muted">
                {{ selectedFilterKeys.length > 0 ? `${selectedFilterKeys.length} selected` : 'Select hierarchy nodes' }}
              </p>
              <div class="flex items-center gap-1">
                <UButton
                  label="Select all"
                  color="primary"
                  variant="ghost"
                  size="sm"
                  @click="selectAllHierarchyFilter"
                />
                <UButton
                  label="Clear"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  :disabled="selectedFilterKeys.length === 0 && filterQuery === ''"
                  @click="clearHierarchyFilter"
                />
              </div>
            </div>

            <div class="mt-3 max-h-80 overflow-y-auto">
              <UTree
                v-model:expanded="expandedFilterKeys"
                :items="filteredFilterTreeItems"
                :model-value="selectedFilterItems"
                :get-key="item => item.id"
                multiple
                selection-behavior="toggle"
                size="sm"
                :ui="{ link: 'p-0 hover:before:bg-transparent' }"
              >
                <template #item="{ selected, indeterminate, expanded, item, handleToggle }">
                  <div
                    class="flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-sm transition-colors hover:bg-elevated/50"
                    @click.stop="handleToggle"
                  >
                    <span
                      role="checkbox"
                      tabindex="0"
                      class="flex size-4 shrink-0 cursor-pointer items-center justify-center rounded-sm"
                      :aria-checked="indeterminate ? 'mixed' : selected"
                      @click.stop="toggleFilterNode(item)"
                      @keydown.enter.stop.prevent="toggleFilterNode(item)"
                      @keydown.space.stop.prevent="toggleFilterNode(item)"
                    >
                      <UIcon
                        :name="indeterminate ? 'i-lucide-square-minus' : selected ? 'i-lucide-square-check' : 'i-lucide-square'"
                        class="size-4"
                        :class="selected || indeterminate ? 'text-primary' : 'text-muted'"
                      />
                    </span>
                    <UIcon
                      :name="item.icon ?? 'i-lucide-box'"
                      class="size-4 shrink-0"
                      :class="item.iconClass ?? 'text-muted'"
                    />
                    <span class="min-w-0 flex-1 truncate text-left">
                      {{ item.label }}
                    </span>
                    <UIcon
                      v-if="item.children?.length"
                      name="i-lucide-chevron-down"
                      class="size-4 shrink-0 text-dimmed transition-transform"
                      :class="expanded ? 'rotate-180' : ''"
                    />
                  </div>
                </template>
              </UTree>

              <p
                v-if="filteredFilterTreeItems.length === 0"
                class="px-2 py-3 text-center text-sm text-muted"
              >
                No matching hierarchy item.
              </p>
            </div>
          </div>
        </template>
      </UPopover>

      <div class="ms-auto flex flex-wrap items-center gap-2 text-xs">
        <UBadge
          v-for="kind in legendKinds"
          :key="kind"
          :color="kindConfig[kind].badgeColor"
          variant="subtle"
          class="gap-1.5"
          :class="kindConfig[kind].badgeClass"
        >
          <UIcon :name="kindConfig[kind].icon" class="size-3 shrink-0" :class="kindConfig[kind].iconClass" />
          {{ kind === 'tenant' || kind === 'project' ? kindConfig[kind].label : assetTypeLabels[kind] }}
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
          :key="flowKey"
          :nodes="visibleNodes"
          :edges="visibleEdges"
          :fit-view-on-init="true"
          :nodes-draggable="false"
          :elements-selectable="false"
          :min-zoom="0.2"
          :max-zoom="1.5"
          @node-click="({ node }) => openDetails(node.data)"
        >
          <Background pattern-color="#64748b" :gap="24" />
          <Controls position="bottom-left" />

          <template #node-hierarchy="{ id, data }">
            <div
              class="relative flex w-60 cursor-pointer flex-col justify-between rounded-md border border-default bg-elevated/95 p-3 shadow-xs transition-all hover:border-primary/60 hover:shadow-md"
              :class="selectedFilterKeys.includes(id) ? 'border-primary ring-2 ring-primary/40 shadow-md' : ''"
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

<style scoped>
:deep(.iiot-highlight-edge .vue-flow__edge-path) {
  filter: drop-shadow(0 0 4px rgb(59 130 246 / 0.7));
}
</style>
