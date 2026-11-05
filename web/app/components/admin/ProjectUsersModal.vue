<script setup lang="ts">
import type { TableColumn, FormSubmitEvent } from '@nuxt/ui'
import { getPaginationRowModel } from '@tanstack/table-core'
import * as z from 'zod'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import type { Project } from '~/types/admin'
import type { Role } from '~/types/auth'

export interface ProjectMemberRow {
  id: string
  name: string
  email: string
  role_id: string
  created_at: string | null
  is_assigned: boolean
}

const props = defineProps<{
  tenantId: string | null
  project: Project | null
}>()

const open = defineModel<boolean>('open', { required: true })

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const toast = useToast()
const table = useTemplateRef('table')
const {
  attachProjectUser,
  loadAvailableProjectUsers,
  loadProjectUsers,
  loadRoles,
  removeProjectUser,
  updateProjectUser
} = useAdmin()

const allUsers = ref<ProjectMemberRow[]>([])
const loading = ref(false)
const search = ref('')
const roleFilter = ref<string>('assigned')
const columnVisibility = ref()
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const pendingUserIds = ref<Set<string>>(new Set())

const editRoleOpen = ref(false)
const editRoleLoading = ref(false)
const editRoleState = reactive<{ role_id: string }>({
  role_id: ''
})

const selectedUser = ref<ProjectMemberRow | null>(null)

const projectRoles = ref<Role[]>([])
const roleOptions = computed(() => projectRoles.value.map(role => ({ label: role.name, value: role.id })))
// New assignments start with the least privileged project role.
const defaultRoleId = computed(() => [...projectRoles.value].sort((a, b) => a.permissions.length - b.permissions.length)[0]?.id)

const roleFilterOptions = computed(() => [
  { label: 'All users', value: 'all' as const },
  { label: 'Assigned', value: 'assigned' as const },
  { label: 'Unassigned', value: 'unassigned' as const },
  ...roleOptions.value
])

const title = computed(() => props.project === null ? 'Project users' : `${props.project.name} users`)

const sortedUsers = computed(() => {
  return [...allUsers.value].sort((a, b) => {
    if (a.is_assigned !== b.is_assigned) {
      return a.is_assigned ? -1 : 1
    }
    return a.name.localeCompare(b.name)
  })
})

const filteredUsers = computed(() => {
  const query = search.value.toLowerCase().trim()

  return sortedUsers.value.filter((user) => {
    const matchesSearch = query === ''
      || user.name.toLowerCase().includes(query)
      || user.email.toLowerCase().includes(query)

    let matchesFilter = true
    if (roleFilter.value === 'assigned') {
      matchesFilter = user.is_assigned
    } else if (roleFilter.value === 'unassigned') {
      matchesFilter = !user.is_assigned
    } else if (roleFilter.value !== 'all') {
      matchesFilter = user.is_assigned && user.role_id === roleFilter.value
    }

    return matchesSearch && matchesFilter
  })
})

const assignedUsers = computed(() => allUsers.value.filter(user => user.is_assigned))

async function reload(): Promise<void> {
  if (!open.value || props.tenantId === null || props.project === null) {
    return
  }

  loading.value = true

  try {
    const [projectUsers, availableUsers, roles] = await Promise.all([
      loadProjectUsers(props.tenantId, props.project.id),
      loadAvailableProjectUsers(props.tenantId, props.project.id),
      loadRoles(props.tenantId, 'project')
    ])
    projectRoles.value = roles
    const initialRoleId = defaultRoleId.value

    if (initialRoleId === undefined) {
      throw new Error('The tenant has no project roles.')
    }

    const rows: ProjectMemberRow[] = availableUsers.map(user => ({
      id: user.id,
      name: user.name,
      email: user.email,
      role_id: initialRoleId,
      created_at: user.created_at,
      is_assigned: false
    }))

    rows.push(...projectUsers.map(user => ({
      id: user.id,
      name: user.name,
      email: user.email,
      role_id: user.role_id,
      created_at: user.created_at,
      is_assigned: true
    })))

    allUsers.value = rows
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to load project users', description: error.message, color: 'error' })
  } finally {
    loading.value = false
  }
}

async function toggleUserAssignment(user: ProjectMemberRow): Promise<void> {
  if (props.tenantId === null || props.project === null) {
    return
  }

  const willAssign = !user.is_assigned
  pendingUserIds.value.add(user.id)

  try {
    if (willAssign) {
      await attachProjectUser(props.tenantId, props.project.id, {
        user_id: user.id,
        role_id: user.role_id
      })
      user.is_assigned = true
      toast.add({
        title: 'User assigned',
        description: `${user.name} has been added to ${props.project.name}.`,
        color: 'success'
      })
    } else {
      await removeProjectUser(props.tenantId, props.project.id, user.id)
      user.is_assigned = false
      toast.add({
        title: 'User removed',
        description: `${user.name} has been removed from ${props.project.name}.`,
        color: 'success'
      })
    }
    allUsers.value = [...allUsers.value]
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: willAssign ? 'Unable to assign user' : 'Unable to remove user',
      description: error.message,
      color: 'error'
    })
  } finally {
    pendingUserIds.value.delete(user.id)
  }
}

function openRoleEdit(user: ProjectMemberRow): void {
  selectedUser.value = user
  editRoleState.role_id = user.role_id
  editRoleOpen.value = true
}

async function submitRole(event: FormSubmitEvent<{ role_id: string }>): Promise<void> {
  if (props.tenantId === null || props.project === null || selectedUser.value === null) {
    throw new Error('A project user is required before updating the role.')
  }

  editRoleLoading.value = true

  try {
    await updateProjectUser(props.tenantId, props.project.id, selectedUser.value.id, event.data.role_id)
    selectedUser.value.role_id = event.data.role_id
    allUsers.value = [...allUsers.value]
    editRoleOpen.value = false
    toast.add({ title: 'Role updated', description: 'The project role has been saved.', color: 'success' })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to update role', description: error.message, color: 'error' })
  } finally {
    editRoleLoading.value = false
  }
}

const columns: TableColumn<ProjectMemberRow>[] = [
  {
    id: 'select',
    header: () => h('span', { class: 'text-xs font-semibold text-muted' }, 'Member'),
    cell: ({ row }) => {
      const isPending = pendingUserIds.value.has(row.original.id)
      return h(UCheckbox, {
        'modelValue': row.original.is_assigned,
        'disabled': isPending || loading.value,
        'ariaLabel': `${row.original.is_assigned ? 'Remove' : 'Assign'} ${row.original.name}`,
        'onUpdate:modelValue': () => void toggleUserAssignment(row.original)
      })
    }
  },
  {
    accessorKey: 'name',
    header: 'Name',
    cell: ({ row }) => h('span', { class: 'font-medium text-highlighted' }, row.original.name)
  },
  {
    accessorKey: 'email',
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Email',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
    cell: ({ row }) => h('span', { class: 'text-muted' }, row.original.email)
  },
  {
    accessorKey: 'role_id',
    header: 'Project role',
    cell: ({ row }) => {
      if (!row.original.is_assigned) {
        return h('span', { class: 'text-xs text-muted' }, '—')
      }

      const role = projectRoles.value.find(item => item.id === row.original.role_id)

      if (role === undefined) {
        throw new Error('The member role is not available.')
      }

      return h('div', { class: 'flex items-center gap-1.5' }, [
        h(UButton, {
          'icon': 'i-lucide-pencil-line',
          'color': 'neutral',
          'variant': 'ghost',
          'size': 'xs',
          'aria-label': `Edit role for ${row.original.name}`,
          'onClick': () => openRoleEdit(row.original)
        }),
        h(UBadge, {
          color: role.is_system ? 'primary' : 'neutral',
          variant: 'subtle'
        }, () => role.name)
      ])
    }
  },
  {
    accessorKey: 'created_at',
    header: 'Created',
    cell: ({ row }) => h('span', { class: 'text-muted' }, row.original.created_at === null ? '—' : new Date(row.original.created_at).toLocaleDateString())
  }
]

watch(
  () => [open.value, props.tenantId, props.project?.id],
  ([open]) => {
    if (open) {
      void reload()
    }
  },
  { immediate: true }
)
</script>

<template>
  <UModal
    v-model:open="open"
    :title="title"
    :ui="{ content: 'sm:max-w-6xl' }"
  >
    <template #body>
      <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <UInput
            v-model="search"
            icon="i-lucide-search"
            placeholder="Search users..."
            class="w-64"
          />
          <div class="flex flex-wrap items-center gap-2">
            <USelect v-model="roleFilter" :items="roleFilterOptions" class="min-w-36" />
          </div>
        </div>

        <AppTable
          ref="table"
          v-model:column-visibility="columnVisibility"
          v-model:pagination="pagination"
          :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
          :data="filteredUsers"
          :columns="columns"
          :loading="loading"
          :ui="{
            base: 'border-separate border-spacing-0',
            thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
            td: 'border-b border-default',
            separator: 'h-0'
          }"
        />

        <div class="flex items-center justify-between gap-3 border-t border-default pt-4">
          <div class="text-sm text-muted">
            {{ assignedUsers.length }} assigned of {{ filteredUsers.length }} user(s).
          </div>
          <UPagination
            :default-page="pagination.pageIndex + 1"
            :items-per-page="pagination.pageSize"
            :total="filteredUsers.length"
            @update:page="(page: number) => table?.tableApi?.setPageIndex(page - 1)"
          />
        </div>
      </div>
    </template>
  </UModal>

  <UModal v-model:open="editRoleOpen" title="Edit project role">
    <template #body>
      <UForm
        :schema="z.object({ role_id: z.string().uuid() })"
        :state="editRoleState"
        class="space-y-4"
        :disabled="editRoleLoading"
        @submit="submitRole"
      >
        <UFormField name="role_id" label="Project role" required>
          <USelect
            v-model="editRoleState.role_id"
            :items="roleOptions"
            class="w-full"
          />
        </UFormField>
        <div class="flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="subtle"
            @click="editRoleOpen = false"
          />
          <UButton label="Save role" type="submit" :loading="editRoleLoading" />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
