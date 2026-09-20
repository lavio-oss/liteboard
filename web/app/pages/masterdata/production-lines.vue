<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useMasterdata } from '~/composables/useMasterdata'
import type {
  Factory,
  MasterdataRow,
  ProductionLine,
  ProductionLineInput
} from '~/types/masterdata'

definePageMeta({
  middleware: 'auth'
})

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const toast = useToast()
const { user } = useAuth()
const tenantId = user.value?.tenants[0]?.id ?? null
const canManage = computed(() => {
  const tenant = user.value?.tenants[0]

  return tenant !== undefined && (tenant.role === 'owner' || tenant.role === 'admin')
})
const addRequested = useState('masterdata:add-requested', () => false)
const {
  createProductionLine,
  deleteProductionLine,
  loadFactories,
  loadProductionLines,
  updateProductionLine
} = useMasterdata()
const formOpen = ref(false)
const formLoading = ref(false)
const selectedFactoryId = ref<number | null>(null)
const editingProductionLine = ref<ProductionLine | null>(null)
const deleteOpen = ref(false)
const deleteLoading = ref(false)
const deletingProductionLine = ref<ProductionLine | null>(null)

const { data: response, status, error, refresh } = await useAsyncData('masterdata-production-lines', async () => {
  if (tenantId === null) {
    return { factories: [] as Factory[], productionLines: [] as ProductionLine[] }
  }

  const [factories, productionLines] = await Promise.all([
    loadFactories(tenantId),
    loadProductionLines(tenantId)
  ])

  return { factories, productionLines }
})

const factories = computed(() => response.value?.factories ?? [])
const productionLines = computed(() => response.value?.productionLines ?? [])
const rows = computed<MasterdataRow[]>(() => productionLines.value)
const factoryOptions = computed(() => factories.value.map(factory => ({
  label: factory.name,
  value: factory.id
})))

function openCreate(): void {
  editingProductionLine.value = null
  selectedFactoryId.value = null
  formOpen.value = true
}

function openEdit(productionLineId: number): void {
  const productionLine = productionLines.value.find(item => item.id === productionLineId)

  if (productionLine === undefined) {
    throw new Error('The selected production line is not available.')
  }

  editingProductionLine.value = productionLine
  selectedFactoryId.value = productionLine.factory_id
  formOpen.value = true
}

function openDelete(productionLineId: number): void {
  const productionLine = productionLines.value.find(item => item.id === productionLineId)

  if (productionLine === undefined) {
    throw new Error('The selected production line is not available.')
  }

  deletingProductionLine.value = productionLine
  deleteOpen.value = true
}

function projectIdForFactory(factoryId: number): number {
  const factory = factories.value.find(item => item.id === factoryId)

  if (factory === undefined) {
    throw new Error('The selected factory is not available.')
  }

  return factory.project_id
}

async function saveProductionLine(payload: ProductionLineInput): Promise<void> {
  if (tenantId === null) {
    throw new Error('A tenant is required before saving a production line.')
  }

  if (selectedFactoryId.value === null) {
    toast.add({
      title: 'Factory is required',
      description: 'Select a factory before saving the production line.',
      color: 'error'
    })

    return
  }

  formLoading.value = true

  try {
    const projectId = projectIdForFactory(selectedFactoryId.value)

    if (editingProductionLine.value === null) {
      await createProductionLine(tenantId, projectId, selectedFactoryId.value, payload)
    } else {
      await updateProductionLine(
        tenantId,
        projectId,
        editingProductionLine.value.factory_id,
        editingProductionLine.value.id,
        payload
      )
    }

    await refresh()
    formOpen.value = false
    toast.add({
      title: editingProductionLine.value === null ? 'Production line created' : 'Production line updated',
      description: 'The production line has been saved.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to save production line',
      description: error.message,
      color: 'error'
    })
  } finally {
    formLoading.value = false
  }
}

async function deleteSelectedProductionLine(): Promise<void> {
  if (tenantId === null || deletingProductionLine.value === null) {
    throw new Error('A production line is required before deletion.')
  }

  deleteLoading.value = true

  try {
    await deleteProductionLine(
      tenantId,
      projectIdForFactory(deletingProductionLine.value.factory_id),
      deletingProductionLine.value.factory_id,
      deletingProductionLine.value.id
    )
    await refresh()
    deleteOpen.value = false
    toast.add({
      title: 'Production line deleted',
      description: 'The production line has been deleted.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to delete production line',
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

const columns: TableColumn<MasterdataRow>[] = [
  {
    id: 'id',
    accessorKey: 'id',
    header: 'ID'
  },
  {
    id: 'name',
    accessorKey: 'name',
    header: 'Name',
    cell: ({ row }) => h('span', { class: 'font-medium text-highlighted' }, row.original.name)
  },
  {
    id: 'code',
    accessorKey: 'code',
    header: 'Code'
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
    cell: ({ row }) => formatDateTime(row.original.created_at)
  },
  {
    id: 'actions',
    header: '',
    cell: ({ row }) => canManage.value
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
    return 'The production lines could not be loaded.'
  }

  return error.value.message
})
</script>

<template>
  <div class="flex flex-col gap-4">
    <UAlert
      v-if="status === 'error'"
      color="error"
      variant="subtle"
      title="Unable to load production lines"
      :description="errorMessage"
    />

    <MasterdataTable
      :columns="columns"
      :data="rows"
      :loading="status === 'pending'"
      search-placeholder="Search production lines..."
    />
  </div>

  <MasterdataFormModal
    v-model:open="formOpen"
    v-model:parent-id="selectedFactoryId"
    resource-type="production-line"
    :resource="editingProductionLine"
    :parent-options="factoryOptions"
    :loading="formLoading"
    @submit-production-line="saveProductionLine"
  />

  <ConfirmDeleteModal
    v-model:open="deleteOpen"
    :title="`Delete ${deletingProductionLine?.name ?? 'production line'}`"
    description="This action cannot be undone."
    :loading="deleteLoading"
    @confirm="deleteSelectedProductionLine"
  />
</template>
