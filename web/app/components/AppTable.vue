<script setup lang="ts" generic="T">
import type { TableColumn } from '@nuxt/ui'

/**
 * The project's table. Use it instead of UTable so every table gets the same treatment of its action column:
 * a column with id "actions" is titled "Actions" and shrinks to fit its buttons.
 */
const props = defineProps<{
  columns: TableColumn<T>[]
  data: T[]
}>()

const table = useTemplateRef('table')

const fittedColumns = computed<TableColumn<T>[]>(() => props.columns.map((column) => {
  if (column.id !== 'actions') {
    return column
  }

  return {
    ...column,
    id: 'actions',
    header: column.header ?? 'Actions',
    meta: {
      ...column.meta,
      class: {
        ...column.meta?.class,
        th: `w-px whitespace-nowrap ${column.meta?.class?.th ?? ''}`,
        td: `w-px whitespace-nowrap ${column.meta?.class?.td ?? ''}`
      }
    }
  }
}))

defineExpose({ tableApi: computed(() => table.value?.tableApi) })
</script>

<template>
  <UTable
    ref="table"
    :data="props.data"
    :columns="fittedColumns"
  >
    <template v-for="(_, name) in $slots" #[name]="slotProps">
      <slot :name="name" v-bind="slotProps ?? {}" />
    </template>
  </UTable>
</template>
