<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useTenantContext } from '~/composables/useTenantContext'
import type { AdminTableRow, Project, ProjectFormInput } from '~/types/admin'
import {
  canDeleteProjectData,
  canManageProjectMembers,
  canWriteProjectData
} from '~/utils/projectPermissions'

definePageMeta({
  middleware: 'auth'
})

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const toast = useToast()
const { isPlatformAdmin, loadTenants, tenants } = useTenantContext()
const addRequested = useState('admin:add-requested', () => false)
const { createProject, deleteProject, loadProjects, updateProject } = useAdmin()
await loadTenants()
const tenantIds = computed(() => tenants.value.map(tenant => tenant.id))
const manageableTenants = computed(() => tenants.value.filter(
  tenant => isPlatformAdmin.value || tenant.role === 'owner' || tenant.role === 'admin'
))
const tenantOptions = computed(() => manageableTenants.value.map(tenant => ({
  label: tenant.name,
  value: tenant.id
})))
const manageableTenantIds = computed(() => new Set(manageableTenants.value.map(tenant => tenant.id)))
const canCreate = computed(() => isPlatformAdmin.value || manageableTenantIds.value.size > 0)
const formOpen = ref(false)
const formLoading = ref(false)
const selectedTenantId = ref<string | null>(null)
const editingProject = ref<Project | null>(null)
const deleteOpen = ref(false)
const deleteLoading = ref(false)
const deletingProject = ref<Project | null>(null)
const managingProject = ref<Project | null>(null)
const projectUsersOpen = ref(false)
const canManageAccounts = computed(() => isPlatformAdmin.value || tenants.value.some(
  tenant => tenant.id === managingProject.value?.tenant_id && (tenant.role === 'owner' || tenant.role === 'admin')
))

const { data: response, status, error, refresh } = await useAsyncData('admin-projects', () => {
  return Promise.all(tenants.value.map(tenant => loadProjects(tenant.id)))
    .then(projectResponses => projectResponses.flat())
}, { watch: [tenantIds] })

const projects = computed(() => response.value ?? [])
const rows = computed<AdminTableRow[]>(() => projects.value)

function openCreate(): void {
  editingProject.value = null
  selectedTenantId.value = null
  formOpen.value = true
}

function openEdit(projectId: string): void {
  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The selected project is not available.')
  }

  editingProject.value = project
  selectedTenantId.value = project.tenant_id
  formOpen.value = true
}

function openDelete(projectId: string): void {
  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The selected project is not available.')
  }

  deletingProject.value = project
  deleteOpen.value = true
}

function openProjectUsers(projectId: string): void {
  const project = projects.value.find(item => item.id === projectId)

  if (project === undefined) {
    throw new Error('The selected project is not available.')
  }

  managingProject.value = project
  projectUsersOpen.value = true
}

async function saveProject(payload: ProjectFormInput): Promise<void> {
  const { tenant_id: projectTenantId, ...attributes } = payload

  formLoading.value = true

  try {
    if (editingProject.value === null) {
      await createProject(projectTenantId, attributes)
    } else {
      await updateProject(editingProject.value.tenant_id, editingProject.value.id, attributes)
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
  if (deletingProject.value === null) {
    throw new Error('A project is required before deletion.')
  }

  deleteLoading.value = true

  try {
    await deleteProject(deletingProject.value.tenant_id, deletingProject.value.id)
    await refresh()
    deleteOpen.value = false
    toast.add({
      title: 'Project deleted',
      description: 'The project and its assets have been deleted.',
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

const columns: TableColumn<AdminTableRow>[] = [
  {
    id: 'sequence',
    header: 'No.',
    cell: ({ row }) => row.index + 1
  },
  {
    id: 'name',
    accessorKey: 'name',
    header: 'Name',
    cell: ({ row }) => h('span', { class: 'font-medium text-highlighted' }, row.original.name)
  },
  {
    id: 'tenant_id',
    accessorKey: 'tenant_id',
    header: 'Tenant',
    cell: ({ row }) => {
      const tenantId = row.original.tenant_id

      if (tenantId === undefined) {
        throw new Error('The project tenant is missing.')
      }

      const tenant = tenants.value.find(item => item.id === tenantId)

      if (tenant === undefined) {
        throw new Error('The project tenant is not available.')
      }

      return tenant.name
    }
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
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Status',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
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
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Created',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
    cell: ({ row }) => row.original.created_at ? formatDateTime(row.original.created_at) : '—'
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const project = projects.value.find(item => item.id === row.original.id)

      if (project === undefined) {
        throw new Error('The selected project is not available.')
      }

      const canManageTenant = manageableTenantIds.value.has(project.tenant_id)
      const actions: ReturnType<typeof h>[] = []

      if (canManageProjectMembers(project.role, canManageTenant)) {
        actions.push(h(UButton, {
          'icon': 'i-lucide-users',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `Manage users for ${project.name}`,
          'onClick': () => openProjectUsers(project.id)
        }))
      }

      if (canWriteProjectData(project.role, canManageTenant)) {
        actions.push(h(UButton, {
          'icon': 'i-lucide-pencil',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `Edit ${project.name}`,
          'onClick': () => openEdit(project.id)
        }))
      }

      if (canDeleteProjectData(project.role, canManageTenant)) {
        actions.push(h(UButton, {
          'icon': 'i-lucide-trash-2',
          'color': 'error',
          'variant': 'ghost',
          'aria-label': `Delete ${project.name}`,
          'onClick': () => openDelete(project.id)
        }))
      }

      return actions.length > 0
        ? h('div', { class: 'flex justify-end gap-1' }, actions)
        : null
    }
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
  <UDashboardPanel id="projects" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Projects">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="canCreate"
            label="Add project"
            icon="i-lucide-plus"
            @click="openCreate"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="status === 'error'"
          color="error"
          variant="subtle"
          title="Unable to load projects"
          :description="errorMessage"
        />

        <AdminTable
          :columns="columns"
          :data="rows"
          :loading="status === 'pending'"
          search-placeholder="Search projects..."
        />
      </div>

      <AdminResourceFormModal
        v-model:open="formOpen"
        v-model:tenant-id="selectedTenantId"
        resource-type="project"
        :resource="editingProject"
        :tenant-options="tenantOptions"
        :loading="formLoading"
        @submit-project="saveProject"
      />

      <ConfirmDeleteModal
        v-model:open="deleteOpen"
        :title="`Delete ${deletingProject?.name ?? 'project'}`"
        description="This action cannot be undone and will delete its assets."
        :loading="deleteLoading"
        @confirm="deleteSelectedProject"
      />

      <AdminProjectUsersModal
        v-if="projectUsersOpen"
        v-model:open="projectUsersOpen"
        :tenant-id="managingProject?.tenant_id ?? null"
        :project="managingProject"
        :can-manage-account="canManageAccounts"
        :can-delete-account="isPlatformAdmin"
      />
    </template>
  </UDashboardPanel>
</template>
