<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { AdminTableRow } from '~/types/admin'

const props = defineProps<{
  columns: TableColumn<AdminTableRow>[]
  data: AdminTableRow[]
  loading?: boolean
  searchPlaceholder: string
}>()

const emit = defineEmits<{
  reset: []
}>()

const table = useTemplateRef('table')
const columnVisibility = ref()
const columnPinning = ref({ right: ['actions'] })
const pagination = ref({
  pageIndex: 0,
  pageSize: 10
})
const search = ref('')
const statusFilter = ref<'all' | AdminTableRow['status']>('all')

function resetFilters(): void {
  search.value = ''
  statusFilter.value = 'all'
  emit('reset')
}

function getColumnKey(column: TableColumn<AdminTableRow>): string {
  return column.id ?? 'column'
}

const filteredData = computed(() => {
  const normalizedSearch = search.value.trim().toLowerCase()

  return props.data.filter((row) => {
    const matchesSearch = normalizedSearch === ''
      || row.name.toLowerCase().includes(normalizedSearch)
      || row.code?.toLowerCase().includes(normalizedSearch)

    const matchesStatus = statusFilter.value === 'all' || row.status === statusFilter.value

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

      <ResetFiltersButton @click="resetFilters" />
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
    :loading="loading"
    :ui="{
      base: 'table-auto border-separate border-spacing-0',
      thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
      tbody: '[&>tr]:last:[&>td]:border-b-0',
      th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r data-[pinned=right]:w-px data-[pinned=right]:whitespace-nowrap data-[pinned=right]:bg-elevated',
      td: 'border-b border-default data-[pinned=right]:w-px data-[pinned=right]:whitespace-nowrap data-[pinned=right]:bg-default',
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
