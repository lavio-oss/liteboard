<script setup lang="ts" generic="T extends SearchableRow">
import type { TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { ResourceStatus, SearchableRow } from '~/types/admin'

const props = withDefaults(defineProps<{
  columns: TableColumn<T>[]
  data: T[]
  loading?: boolean
  searchPlaceholder: string
  showStatusFilter?: boolean
}>(), {
  showStatusFilter: true
})

const emit = defineEmits<{
  reset: []
  reload: []
}>()

const table = useTemplateRef('table')
const columnVisibility = ref()
const columnPinning = ref({ right: ['actions'] })
const pagination = ref({
  pageIndex: 0,
  pageSize: 10
})
const search = ref('')
const statusFilter = ref<'all' | ResourceStatus>('all')
const isReloading = ref(false)
const minLoading = ref(false)
let minLoadingTimer: ReturnType<typeof setTimeout> | undefined
let loadingStartTime = 0

const { withScreenLoading } = useScreenLoading()

watch(() => props.loading, (loading) => {
  if (loading) {
    loadingStartTime = Date.now()
    minLoading.value = true
    if (minLoadingTimer) {
      clearTimeout(minLoadingTimer)
      minLoadingTimer = undefined
    }
  } else if (minLoading.value) {
    const elapsed = Date.now() - loadingStartTime
    const remaining = Math.max(0, 400 - elapsed)
    if (remaining > 0) {
      if (minLoadingTimer) clearTimeout(minLoadingTimer)
      minLoadingTimer = setTimeout(() => {
        minLoading.value = false
        minLoadingTimer = undefined
      }, remaining)
    } else {
      minLoading.value = false
    }
  }
}, { immediate: true })

onScopeDispose(() => {
  if (minLoadingTimer) {
    clearTimeout(minLoadingTimer)
  }
})

const effectiveLoading = computed(() => Boolean(props.loading || minLoading.value || isReloading.value))

async function resetFilters(): Promise<void> {
  search.value = ''
  statusFilter.value = 'all'
  emit('reset')
  emit('reload')
  isReloading.value = true
  try {
    await withScreenLoading(async () => {
      await refreshNuxtData()
    }, 400)
  } finally {
    isReloading.value = false
  }
}

function getColumnKey(column: TableColumn<T>): string {
  return column.id ?? 'column'
}

const filteredData = computed(() => {
  const normalizedSearch = search.value.trim().toLowerCase()

  return props.data.filter((row) => {
    const matchesSearch = normalizedSearch === ''
      || row.name.toLowerCase().includes(normalizedSearch)
      || row.code?.toLowerCase().includes(normalizedSearch)

    const matchesStatus = !props.showStatusFilter
      || statusFilter.value === 'all'
      || row.status === statusFilter.value

    return matchesSearch && matchesStatus
  })
})

const displayColumns = computed(() => props.columns
  .filter(column => getColumnKey(column) !== 'actions')
  .map(column => ({
    label: upperFirst(getColumnKey(column)),
    type: 'checkbox' as const,
    checked: columnVisibility.value?.[getColumnKey(column)] !== false,
    onUpdateChecked(checked: boolean) {
      table.value?.tableApi?.getColumn(getColumnKey(column))?.toggleVisibility(checked)
    },
    onSelect(event?: Event) {
      event?.preventDefault()
    }
  })))

watch(filteredData, () => {
  pagination.value.pageIndex = 0
})
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-1.5">
    <div class="flex flex-wrap items-center gap-1.5 flex-1 min-w-0">
      <UInput
        v-model="search"
        class="w-64"
        icon="i-lucide-search"
        :placeholder="searchPlaceholder"
      />

      <slot name="search-trailing" />
    </div>

    <div class="flex flex-wrap items-center gap-1.5">
      <slot name="filters" />

      <USelect
        v-if="props.showStatusFilter"
        v-model="statusFilter"
        :items="[
          { label: 'All', value: 'all' },
          { label: 'Active', value: 'active' },
          { label: 'Inactive', value: 'inactive' }
        ]"
        :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
        placeholder="Filter status"
        class="min-w-28"
      />

      <UDropdownMenu
        :items="[displayColumns]"
        :content="{ align: 'end' }"
      >
        <UButton
          label="Display"
          color="neutral"
          variant="outline"
          trailing-icon="i-lucide-settings-2"
        />
      </UDropdownMenu>

      <ResetFiltersButton :loading="effectiveLoading" @click="resetFilters" />
    </div>
  </div>

  <UTable
    ref="table"
    v-model:column-visibility="columnVisibility"
    v-model:column-pinning="columnPinning"
    v-model:pagination="pagination"
    :pagination-options="{
      getPaginationRowModel: getPaginationRowModel()
    }"
    class="shrink-0"
    :data="filteredData"
    :columns="columns"
    :loading="effectiveLoading"
    :ui="{
      base: 'border-separate border-spacing-0',
      thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
      tbody: '[&>tr]:last:[&>td]:border-b-0',
      th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r data-[pinned=right]:w-px data-[pinned=right]:min-w-max data-[pinned=right]:whitespace-nowrap data-[pinned=right]:border-l data-[pinned=right]:bg-elevated data-[pinned=right]:z-20',
      td: 'border-b border-default data-[pinned=right]:w-px data-[pinned=right]:min-w-max data-[pinned=right]:whitespace-nowrap data-[pinned=right]:border-l data-[pinned=right]:bg-default data-[pinned=right]:z-10',
      separator: 'h-0'
    }"
  />

  <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
    <div class="text-sm text-muted">
      {{ filteredData.length }} row(s).
    </div>

    <UPagination
      :default-page="pagination.pageIndex + 1"
      :items-per-page="pagination.pageSize"
      :total="filteredData.length"
      @update:page="(page: number) => table?.tableApi?.setPageIndex(page - 1)"
    />
  </div>
</template>
