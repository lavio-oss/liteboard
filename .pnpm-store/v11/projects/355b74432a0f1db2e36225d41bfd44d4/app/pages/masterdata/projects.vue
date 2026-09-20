<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useMasterdata } from '~/composables/useMasterdata'
import type { MasterdataRow, Project, ProjectInput } from '~/types/masterdata'

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
const { createProject, deleteProject, loadProjects, updateProject } = useMasterdata()
const formOpen = ref(false)
const formLoading = ref(false)
const editingProject = ref<Project | null>(null)
const deleteOpen = ref(false)
const deleteLoading = ref(false)
const deletingProject = ref<Project | null>(null)

const { data: response, status, error, refresh } = await useAsyncData('masterdata-projects', () => {
  if (tenantId === null) {
    return Promise.resolve([] as Project[])
  }

  return loadProjects(tenantId)
})

const projects = computed(() => response.value ?? [])
const rows = computed<MasterdataRow[]>(() => projects.value)

function openCreate(): void {
  editingProject.value = null
  formOpen.value = true
}

function openEdit(projectId: number): void {
  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The selected project is not available.')
  }

  editingProject.value = project
  formOpen.value = true
}

function openDelete(projectId: number): void {
  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The selected project is not available.')
  }

  deletingProject.value = project
  deleteOpen.value = true
}

async function saveProject(payload: ProjectInput): Promise<void> {
  if (tenantId === null) {
    throw new Error('A tenant is required before saving a project.')
  }

  formLoading.value = true

  try {
    if (editingProject.value === null) {
      await createProject(tenantId, payload)
    } else {
      await updateProject(tenantId, editingProject.value.id, payload)
    }

    await refresh()
    formOpen.value = false
    toast.add({
      title: editingProject.value === null ? 'Project created' : 'Project updated',
      description: 'The project has been saved.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to save project',
      description: error.message,
      color: 'error'
    })
  } finally {
    formLoading.value = false
  }
}

async function deleteSelectedProject(): Promise<void> {
  if (tenantId === null || deletingProject.value === null) {
    throw new Error('A project is required before deletion.')
  }

  deleteLoading.value = true

  try {
    await deleteProject(tenantId, deletingProject.value.id)
    await refresh()
    deleteOpen.value = false
    toast.add({
      title: 'Project deleted',
      description: 'The project and its child masterdata have been deleted.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to delete project',
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
    id: 'description',
    accessorKey: 'description',
    header: 'Description',
    cell: ({ row }) => row.original.description || '—'
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
    return 'The projects could not be loaded.'
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
      title="Unable to load projects"
      :description="errorMessage"
    />

    <MasterdataTable
      :columns="columns"
      :data="rows"
      :loading="status === 'pending'"
      search-placeholder="Search projects..."
    />
  </div>

  <MasterdataFormModal
    v-model:open="formOpen"
    resource-type="project"
    :resource="editingProject"
    :loading="formLoading"
    @submit-project="saveProject"
  />

  <ConfirmDeleteModal
    v-model:open="deleteOpen"
    :title="`Delete ${deletingProject?.name ?? 'project'}`"
    description="This action cannot be undone and will delete its factories and production lines."
    :loading="deleteLoading"
    @confirm="deleteSelectedProject"
  />
</template>
