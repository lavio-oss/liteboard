<script setup lang="ts">
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { Handle, Position, VueFlow } from '@vue-flow/core'
import type { Edge, Node } from '@vue-flow/core'
import { ApiError } from '~/composables/useApi'
import { useMasterdata } from '~/composables/useMasterdata'
import type { Tenant } from '~/types/auth'
import type { Factory, ProductionLine, Project, TenantHierarchy } from '~/types/masterdata'

const props = defineProps<{
  tenants: Tenant[]
}>()

type FlowNodeData = {
  label: string
  kind: 'tenant' | 'project' | 'factory' | 'production-line'
  code?: string | null
  sublabel?: string | null
  rawItem: Tenant | Project | Factory | ProductionLine
}

type TreeNode = {
  id: string
  label: string
  kind: FlowNodeData['kind']
  code?: string | null
  sublabel?: string | null
  rawItem: Tenant | Project | Factory | ProductionLine
  children: TreeNode[]
  x: number
  y: number
  subtreeWidth: number
}

const kindConfig = {
  'tenant': {
    label: 'Tenant',
    icon: 'i-lucide-building-2',
    iconClass: 'text-emerald-500',
    badgeColor: 'success' as const
  },
  'project': {
    label: 'Project',
    icon: 'i-lucide-folder-kanban',
    iconClass: 'text-blue-500',
    badgeColor: 'primary' as const
  },
  'factory': {
    label: 'Factory',
    icon: 'i-lucide-building',
    iconClass: 'text-purple-500',
    badgeColor: 'secondary' as const
  },
  'production-line': {
    label: 'Production Line',
    icon: 'i-lucide-git-branch',
    iconClass: 'text-amber-500',
    badgeColor: 'warning' as const
  }
}

function getKindConfig(kind: unknown) {
  if (kind === 'tenant' || kind === 'project' || kind === 'factory' || kind === 'production-line') {
    return kindConfig[kind]
  }

  return kindConfig.tenant
}

const selectedTenantId = ref<number | null>(props.tenants[0]?.id ?? null)
const hierarchy = ref<TenantHierarchy | null>(null)
const loading = ref(false)
const error = ref<ApiError | null>(null)
const detailsOpen = ref(false)
const selectedNode = ref<FlowNodeData | null>(null)
const { loadTenantHierarchy } = useMasterdata()

const tenantOptions = computed(() => props.tenants.map(tenant => ({
  label: `${tenant.name} (${tenant.code})`,
  value: tenant.id
})))

const selectedTenant = computed(() => props.tenants.find(tenant => tenant.id === selectedTenantId.value))

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

type DetailField = {
  label: string
  value: string | number | null | undefined
  isBadge?: boolean
  badgeColor?: 'success' | 'neutral' | 'primary' | 'secondary' | 'warning'
}

const detailFields = computed<DetailField[]>(() => {
  if (selectedNode.value === null) {
    return []
  }

  const item = selectedNode.value.rawItem as Record<string, unknown>
  const kind = selectedNode.value.kind

  const fields: DetailField[] = [
    { label: 'ID', value: item.id as number },
    { label: 'Name', value: item.name as string }
  ]

  if (typeof item.code === 'string' && item.code !== '') {
    fields.push({ label: 'Code', value: item.code })
  }

  if (typeof item.status === 'string') {
    fields.push({
      label: 'Status',
      value: item.status,
      isBadge: true,
      badgeColor: item.status === 'active' ? 'success' : 'neutral'
    })
  }

  if (kind === 'tenant' && typeof item.role === 'string') {
    fields.push({
      label: 'Your role',
      value: item.role,
      isBadge: true,
      badgeColor: item.role === 'owner' ? 'primary' : 'neutral'
    })
  }

  if (kind === 'factory' && typeof item.location === 'string' && item.location !== '') {
    fields.push({ label: 'Location', value: item.location })
  }

  if (kind === 'project' && typeof item.description === 'string' && item.description !== '') {
    fields.push({ label: 'Description', value: item.description })
  }

  if (typeof item.created_at === 'string') {
    fields.push({ label: 'Created at', value: formatDateTime(item.created_at) })
  }

  if (typeof item.updated_at === 'string') {
    fields.push({ label: 'Updated at', value: formatDateTime(item.updated_at) })
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

  // 1. Build tree structure and calculate subtree widths bottom-up
  function buildProjectTree(project: Project): TreeNode {
    const factoryChildren = currentHierarchy!.factories
      .filter(f => f.project_id === project.id)
      .map((factory) => {
        const lineChildren: TreeNode[] = currentHierarchy!.productionLines
          .filter(l => l.factory_id === factory.id)
          .map(line => ({
            id: `production-line-${line.id}`,
            label: line.name,
            kind: 'production-line' as const,
            code: line.code,
            rawItem: line,
            children: [],
            x: 0,
            y: 460,
            subtreeWidth: CARD_WIDTH
          }))

        const linesTotalWidth = lineChildren.length > 0
          ? lineChildren.reduce((sum, c) => sum + c.subtreeWidth, 0) + (lineChildren.length - 1) * CARD_GAP
          : CARD_WIDTH

        return {
          id: `factory-${factory.id}`,
          label: factory.name,
          kind: 'factory' as const,
          code: factory.code,
          sublabel: factory.location,
          rawItem: factory,
          children: lineChildren,
          x: 0,
          y: 320,
          subtreeWidth: Math.max(CARD_WIDTH, linesTotalWidth)
        }
      })

    const factoriesTotalWidth = factoryChildren.length > 0
      ? factoryChildren.reduce((sum, c) => sum + c.subtreeWidth, 0) + (factoryChildren.length - 1) * CARD_GAP
      : CARD_WIDTH

    return {
      id: `project-${project.id}`,
      label: project.name,
      kind: 'project' as const,
      sublabel: project.description,
      rawItem: project,
      children: factoryChildren,
      x: 0,
      y: 180,
      subtreeWidth: Math.max(CARD_WIDTH, factoriesTotalWidth)
    }
  }

  const projectNodes = currentHierarchy.projects.map(buildProjectTree)

  const projectsTotalWidth = projectNodes.length > 0
    ? projectNodes.reduce((sum, c) => sum + c.subtreeWidth, 0) + (projectNodes.length - 1) * CARD_GAP
    : CARD_WIDTH

  const tenantNode: TreeNode = {
    id: `tenant-${currentTenant.id}`,
    label: currentTenant.name,
    kind: 'tenant',
    code: currentTenant.code,
    rawItem: currentTenant,
    children: projectNodes,
    x: 0,
    y: 40,
    subtreeWidth: Math.max(CARD_WIDTH, projectsTotalWidth)
  }

  // 2. Center each parent horizontally directly above its children
  function layoutSubtree(node: TreeNode, leftX: number): void {
    if (node.children.length === 0) {
      node.x = leftX + (node.subtreeWidth - CARD_WIDTH) / 2

      return
    }

    const childrenWidth = node.children.reduce((sum, c) => sum + c.subtreeWidth, 0)
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
      const childrenCenter = (firstChildCenter + lastChildCenter) / 2

      node.x = childrenCenter - CARD_WIDTH / 2
    }
  }

  const startLeftX = Math.max(80, 600 - tenantNode.subtreeWidth / 2)
  layoutSubtree(tenantNode, startLeftX)

  // 3. Flatten tree into Vue Flow nodes
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

    currentHierarchy.factories
      .filter(factory => factory.project_id === project.id)
      .forEach((factory) => {
        result.push({
          id: `project-${project.id}-factory-${factory.id}`,
          source: `project-${project.id}`,
          target: `factory-${factory.id}`,
          type: 'smoothstep',
          animated: true,
          style: { stroke: 'var(--ui-border-accented, #94a3b8)', strokeWidth: 1.5 }
        })

        currentHierarchy.productionLines
          .filter(productionLine => productionLine.factory_id === factory.id)
          .forEach((productionLine) => {
            result.push({
              id: `factory-${factory.id}-line-${productionLine.id}`,
              source: `factory-${factory.id}`,
              target: `production-line-${productionLine.id}`,
              type: 'smoothstep',
              animated: true,
              style: { stroke: 'var(--ui-border-accented, #94a3b8)', strokeWidth: 1.5 }
            })
          })
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
          <UIcon name="i-lucide-building" class="size-3" />
          Factory
        </UBadge>
        <UBadge color="warning" variant="subtle" class="gap-1">
          <UIcon name="i-lucide-git-branch" class="size-3" />
          Production Line
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
                    {{ getKindConfig(data.kind).label }}
                  </span>
                </div>

                <UButton
                  icon="i-lucide-info"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  class="-mr-1.5 -my-1 text-muted hover:text-highlighted cursor-pointer"
                  aria-label="View details"
                  @click.stop="openDetails(data)"
                  @pointerdown.stop
                  @mousedown.stop
                />
              </div>

              <div class="text-sm font-semibold text-highlighted truncate" :title="data.label">
                {{ data.label }}
              </div>

              <div
                v-if="data.code || data.sublabel"
                class="flex items-center gap-1.5 text-xs text-muted truncate mt-1.5"
              >
                <span
                  v-if="data.code"
                  class="font-mono bg-elevated px-1 py-0.5 rounded text-[11px] border border-default"
                >
                  {{ data.code }}
                </span>
                <span v-if="data.code && data.sublabel" class="text-muted/60">•</span>
                <span v-if="data.sublabel" class="truncate text-[11px]">
                  {{ data.sublabel }}
                </span>
              </div>

              <Handle
                v-if="data.kind !== 'production-line'"
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
    :title="selectedNode ? `${getKindConfig(selectedNode.kind).label} Details` : 'Details'"
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
              {{ getKindConfig(selectedNode.kind).label }}
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
              <span v-else class="font-mono">{{ field.value || '—' }}</span>
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
</template>
