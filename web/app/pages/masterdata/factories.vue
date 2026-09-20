<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useMasterdata } from '~/composables/useMasterdata'
import type { Factory, FactoryInput, MasterdataRow, Project } from '~/types/masterdata'

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
const { createFactory, deleteFactory, loadFactories, loadProjects, updateFactory } = useMasterdata()
const formOpen = ref(false)
const formLoading = ref(false)
const selectedProjectId = ref<number | null>(null)
const editingFactory = ref<Factory | null>(null)
const deleteOpen = ref(false)
const deleteLoading = ref(false)
const deletingFactory = ref<Factory | null>(null)

const { data: response, status, error, refresh } = await useAsyncData('masterdata-factories', async () => {
  if (tenantId === null) {
    return { factories: [] as Factory[], projects: [] as Project[] }
  }

  const [factories, projects] = await Promise.all([
    loadFactories(tenantId),
    loadProjects(tenantId)
  ])

  return { factories, projects }
})

const factories = computed(() => response.value?.factories ?? [])
const projects = computed(() => response.value?.projects ?? [])
const rows = computed<MasterdataRow[]>(() => factories.value)
const projectOptions = computed(() => projects.value.map(project => ({
  label: project.name,
  value: project.id
})))

function openCreate(): void {
  editingFactory.value = null
  selectedProjectId.value = null
  formOpen.value = true
}

function openEdit(factoryId: number): void {
  const factory = factories.value.find(item => item.id === factoryId)

  if (factory === undefined) {
    throw new Error('The selected factory is not available.')
  }

  editingFactory.value = factory
  selectedProjectId.value = factory.project_id
  formOpen.value = true
}

function openDelete(factoryId: number): void {
  const factory = factories.value.find(item => item.id === factoryId)

  if (factory === undefined) {
    throw new Error('The selected factory is not available.')
  }

  deletingFactory.value = factory
  deleteOpen.value = true
}

async function saveFactory(payload: FactoryInput): Promise<void> {
  if (tenantId === null) {
    throw new Error('A tenant is required before saving a factory.')
  }

  if (selectedProjectId.value === null) {
    toast.add({
      title: 'Project is required',
      description: 'Select a project before saving the factory.',
      color: 'error'
    })

    return
  }

  formLoading.value = true

  try {
    if (editingFactory.value === null) {
      await createFactory(tenantId, selectedProjectId.value, payload)
    } else {
      await updateFactory(tenantId, editingFactory.value.project_id, editingFactory.value.id, payload)
    }

    await refresh()
    formOpen.value = false
    toast.add({
      title: editingFactory.value === null ? 'Factory created' : 'Factory updated',
      description: 'The factory has been saved.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to save factory',
      description: error.message,
      color: 'error'
    })
  } finally {
    formLoading.value = false
  }
}

async function deleteSelectedFactory(): Promise<void> {
  if (tenantId === null || deletingFactory.value === null) {
    throw new Error('A factory is required before deletion.')
  }

  deleteLoading.value = true

  try {
    await deleteFactory(tenantId, deletingFactory.value.project_id, deletingFactory.value.id)
    await refresh()
    deleteOpen.value = false
    toast.add({
      title: 'Factory deleted',
      description: 'The factory and its production lines have been deleted.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to delete factory',
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
    id: 'location',
    accessorKey: 'location',
    header: 'Location',
    cell: ({ row }) => row.original.location || '—'
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
    return 'The factories could not be loaded.'
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
      title="Unable to load factories"
      :description="errorMessage"
    />

    <MasterdataTable
      :columns="columns"
      :data="rows"
      :loading="status === 'pending'"
      search-placeholder="Search factories..."
    />
  </div>

  <MasterdataFormModal
    v-model:open="formOpen"
    v-model:parent-id="selectedProjectId"
    resource-type="factory"
    :resource="editingFactory"
    :parent-options="projectOptions"
    :loading="formLoading"
    @submit-factory="saveFactory"
  />

  <ConfirmDeleteModal
    v-model:open="deleteOpen"
    :title="`Delete ${deletingFactory?.name ?? 'factory'}`"
    description="This action cannot be undone and will delete its production lines."
    :loading="deleteLoading"
    @confirm="deleteSelectedFactory"
  />
</template>
