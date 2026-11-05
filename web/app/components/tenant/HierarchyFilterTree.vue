<script setup lang="ts">
import { flattenFilterItems } from '~/utils/hierarchyTree'
import type { HierarchyFilterItem } from '~/utils/hierarchyTree'

const props = defineProps<{
  items: HierarchyFilterItem[]
}>()

const selectedKeys = defineModel<string[]>('selectedKeys', { required: true })

const open = ref(false)
const query = ref('')
const expandedKeys = ref<string[]>([])

const selectedKeySet = computed(() => new Set(selectedKeys.value))

const filteredItems = computed(() => filterByQuery(props.items, query.value))
const selectedItems = computed(() => flattenFilterItems(props.items).filter(item => selectedKeySet.value.has(item.id)))

const placeholder = computed(() => {
  const labels = topLevelSelected(props.items).map(item => item.label)

  return labels.length === 0 ? 'Search nodes, code, or hierarchy...' : labels.join(', ')
})

function filterByQuery(items: HierarchyFilterItem[], text: string): HierarchyFilterItem[] {
  const needle = text.trim().toLocaleLowerCase()

  if (needle === '') {
    return items
  }

  return items.flatMap((item) => {
    if ((item.searchText ?? item.label).toLocaleLowerCase().includes(needle)) {
      return [item]
    }

    const children = filterByQuery(item.children ?? [], needle)

    return children.length === 0 ? [] : [{ ...item, children }]
  })
}

function topLevelSelected(items: HierarchyFilterItem[]): HierarchyFilterItem[] {
  return items.flatMap(item => selectedKeySet.value.has(item.id) ? [item] : topLevelSelected(item.children ?? []))
}

function expandableKeys(items: HierarchyFilterItem[]): string[] {
  return items.flatMap(item => item.children?.length ? [item.id, ...expandableKeys(item.children)] : [])
}

function toggleNode(item: HierarchyFilterItem): void {
  const ids = flattenFilterItems([item]).map(node => node.id)

  selectedKeys.value = ids.every(id => selectedKeySet.value.has(id))
    ? selectedKeys.value.filter(id => !ids.includes(id))
    : [...new Set([...selectedKeys.value, ...ids])]
}

function selectAll(): void {
  selectedKeys.value = [...new Set([...selectedKeys.value, ...flattenFilterItems(filteredItems.value).map(item => item.id)])]
}

function clear(): void {
  selectedKeys.value = []
  query.value = ''
  expandedKeys.value = []
}

watch(open, (isOpen) => {
  query.value = ''

  if (isOpen) {
    expandedKeys.value = []
  }
})

watch(query, (text) => {
  if (open.value) {
    expandedKeys.value = text === '' ? [] : expandableKeys(filteredItems.value)
  }
})

watch(() => props.items, () => {
  open.value = false
  query.value = ''
})
</script>

<template>
  <UPopover v-model:open="open">
    <div class="inline-flex min-w-80">
      <UInput
        v-model="query"
        icon="i-lucide-search"
        class="w-full"
        :ui="{ base: 'pe-20 truncate placeholder:truncate' }"
        :placeholder="placeholder"
        :title="selectedKeys.length > 0 ? placeholder : undefined"
        aria-label="Filter hierarchy"
        @click.stop="open = true"
        @focus="open = true"
        @keydown.escape="open = false"
      >
        <template #trailing>
          <UBadge
            v-if="selectedKeys.length > 0"
            color="primary"
            variant="subtle"
            class="mr-1"
          >
            {{ selectedKeys.length }}
          </UBadge>
          <UIcon
            name="i-lucide-chevron-down"
            class="size-4 cursor-pointer text-dimmed transition-transform"
            :class="open ? 'rotate-180' : ''"
            @click.stop="open = !open"
          />
        </template>
      </UInput>
    </div>

    <template #content>
      <div class="w-[min(32rem,calc(100vw-2rem))] p-3">
        <div class="flex items-center justify-between gap-2">
          <p class="text-xs text-muted">
            {{ selectedKeys.length > 0 ? `${selectedKeys.length} selected` : 'Select hierarchy nodes' }}
          </p>
          <div class="flex items-center gap-1">
            <UButton
              label="Select all"
              color="primary"
              variant="ghost"
              size="sm"
              @click="selectAll"
            />
            <UButton
              label="Clear"
              color="neutral"
              variant="ghost"
              size="sm"
              :disabled="selectedKeys.length === 0 && query === ''"
              @click="clear"
            />
          </div>
        </div>

        <div class="mt-3 max-h-80 overflow-y-auto">
          <UTree
            v-model:expanded="expandedKeys"
            :items="filteredItems"
            :model-value="selectedItems"
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
                  @click.stop="toggleNode(item)"
                  @keydown.enter.stop.prevent="toggleNode(item)"
                  @keydown.space.stop.prevent="toggleNode(item)"
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
            v-if="filteredItems.length === 0"
            class="px-2 py-3 text-center text-sm text-muted"
          >
            No matching hierarchy item.
          </p>
        </div>
      </div>
    </template>
  </UPopover>
</template>
