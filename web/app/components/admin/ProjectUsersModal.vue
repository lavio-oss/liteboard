<script setup lang="ts">
import type { TableColumn, FormSubmitEvent } from '@nuxt/ui'
import { getPaginationRowModel } from '@tanstack/table-core'
import * as z from 'zod'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import type { Project, ProjectRole } from '~/types/admin'
import { projectRoleSchema } from '~/types/admin'

export interface ProjectMemberRow {
  id: string
  name: string
  email: string
  role: ProjectRole
  is_assigned: boolean
}

const props = withDefaults(defineProps<{
  tenantId: string | null
  project: Project | null
  canManageAccount?: boolean
  canDeleteAccount?: boolean
}>(), {
  canManageAccount: false,
  canDeleteAccount: false
})

const open = defineModel<boolean>('open', { required: true })

type AccountForm = {
  name: string
  email: string
  password: string
  password_confirmation: string
}

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const toast = useToast()
const table = useTemplateRef('table')
const {
  attachProjectUser,
  deleteTenantUser,
  loadAvailableProjectUsers,
  loadProjectUsers,
  removeProjectUser,
  updateProjectUser,
  updateTenantUser
} = useAdmin()

const allUsers = ref<ProjectMemberRow[]>([])
const loading = ref(false)
const search = ref('')
const roleFilter = ref<'all' | 'assigned' | 'unassigned' | ProjectRole>('all')
const columnVisibility = ref()
const columnPinning = ref({ right: ['actions'] })
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const pendingUserIds = ref<Set<string>>(new Set())

const editRoleOpen = ref(false)
const editRoleLoading = ref(false)
const editRoleState = reactive<{ role: ProjectRole }>({
  role: 'viewer'
})

const editAccountOpen = ref(false)
const editAccountLoading = ref(false)
const deleteAccountOpen = ref(false)
const deleteAccountLoading = ref(false)
const selectedUser = ref<ProjectMemberRow | null>(null)

const accountSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  email: z.string().email('Enter a valid email address'),
  password: z.string().refine(value => value === '' || value.length >= 8, 'Password must be at least 8 characters'),
  password_confirmation: z.string()
}).refine(value => value.password === value.password_confirmation, {
  message: 'Passwords do not match',
  path: ['password_confirmation']
})
const accountState = reactive<AccountForm>({
  name: '',
  email: '',
  password: '',
  password_confirmation: ''
})
const roleOptions = [
  { label: 'Admin', value: 'admin' as const },
  { label: 'Engineer', value: 'engineer' as const },
  { label: 'Operator', value: 'operator' as const },
  { label: 'Viewer', value: 'viewer' as const }
]

const roleFilterOptions = [
  { label: 'All users', value: 'all' as const },
  { label: 'Assigned', value: 'assigned' as const },
  { label: 'Unassigned', value: 'unassigned' as const },
  ...roleOptions
]

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
      matchesFilter = user.is_assigned && user.role === roleFilter.value
    }

    return matchesSearch && matchesFilter
  })
})

const assignedUsers = computed(() => allUsers.value.filter(user => user.is_assigned))

function roleLabel(role: ProjectRole): string {
  return roleOptions.find(option => option.value === role)?.label ?? role
}

async function reload(): Promise<void> {
  if (!open.value || props.tenantId === null || props.project === null) {
    return
  }

  loading.value = true

  try {
    const [projectUsers, availableUsers] = await Promise.all([
      loadProjectUsers(props.tenantId, props.project.id),
      loadAvailableProjectUsers(props.tenantId, props.project.id)
    ])

    const rows: ProjectMemberRow[] = availableUsers.map(user => ({
      id: user.id,
      name: user.name,
      email: user.email,
      role: 'viewer' as const,
      is_assigned: false
    }))

    rows.push(...projectUsers.map(user => ({
      id: user.id,
      name: user.name,
      email: user.email,
      role: user.role,
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
        role: user.role
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
  editRoleState.role = user.role
  editRoleOpen.value = true
}

async function submitRole(event: FormSubmitEvent<{ role: ProjectRole }>): Promise<void> {
  if (props.tenantId === null || props.project === null || selectedUser.value === null) {
    throw new Error('A project user is required before updating the role.')
  }

  editRoleLoading.value = true

  try {
    await updateProjectUser(props.tenantId, props.project.id, selectedUser.value.id, event.data.role)
    selectedUser.value.role = event.data.role
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

function openAccountEdit(user: ProjectMemberRow): void {
  selectedUser.value = user
  accountState.name = user.name
  accountState.email = user.email
  accountState.password = ''
  accountState.password_confirmation = ''
  editAccountOpen.value = true
}

async function submitAccount(event: FormSubmitEvent<AccountForm>): Promise<void> {
  if (props.tenantId === null || selectedUser.value === null) {
    throw new Error('A user is required before updating the account.')
  }

  editAccountLoading.value = true

  try {
    const payload = event.data.password === ''
      ? { name: event.data.name, email: event.data.email }
      : {
          name: event.data.name,
          email: event.data.email,
          password: event.data.password,
          password_confirmation: event.data.password_confirmation
        }

    await updateTenantUser(props.tenantId, selectedUser.value.id, payload)
    selectedUser.value.name = event.data.name
    selectedUser.value.email = event.data.email
    allUsers.value = [...allUsers.value]
    editAccountOpen.value = false
    toast.add({ title: 'Account updated', description: 'The account has been saved.', color: 'success' })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to update account', description: error.message, color: 'error' })
  } finally {
    editAccountLoading.value = false
  }
}

async function confirmDeleteAccount(): Promise<void> {
  if (props.tenantId === null || selectedUser.value === null) {
    throw new Error('A user is required before deletion.')
  }

  deleteAccountLoading.value = true

  try {
    await deleteTenantUser(props.tenantId, selectedUser.value.id)
    await reload()
    deleteAccountOpen.value = false
    toast.add({ title: 'Account deleted', description: 'The global account has been deleted.', color: 'success' })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to delete account', description: error.message, color: 'error' })
  } finally {
    deleteAccountLoading.value = false
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
    accessorKey: 'role',
    header: 'Project role',
    cell: ({ row }) => {
      if (!row.original.is_assigned) {
        return h('span', { class: 'text-xs text-muted' }, '—')
      }

      return h('div', { class: 'flex items-center gap-1.5' }, [
        h(UBadge, {
          color: row.original.role === 'admin' ? 'primary' : 'neutral',
          variant: 'subtle'
        }, () => roleLabel(row.original.role)),
        h(UButton, {
          'icon': 'i-lucide-pencil-line',
          'color': 'neutral',
          'variant': 'ghost',
          'size': 'xs',
          'aria-label': `Edit role for ${row.original.name}`,
          'onClick': () => openRoleEdit(row.original)
        })
      ])
    }
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      ...(props.canManageAccount
        ? [h(UButton, {
            'icon': 'i-lucide-user-pen',
            'color': 'neutral',
            'variant': 'ghost',
            'aria-label': `Edit account for ${row.original.name}`,
            'onClick': () => openAccountEdit(row.original)
          })]
        : []),
      ...(props.canDeleteAccount
        ? [h(UButton, {
            'icon': 'i-lucide-trash-2',
            'color': 'error',
            'variant': 'ghost',
            'aria-label': `Delete account for ${row.original.name}`,
            'onClick': () => {
              selectedUser.value = row.original
              deleteAccountOpen.value = true
            }
          })]
        : [])
    ])
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

        <UTable
          ref="table"
          v-model:column-visibility="columnVisibility"
          v-model:column-pinning="columnPinning"
          v-model:pagination="pagination"
          :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
          :data="filteredUsers"
          :columns="columns"
          :loading="loading"
          :ui="{
            base: 'border-separate border-spacing-0',
            thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r data-[pinned=right]:min-w-max data-[pinned=right]:whitespace-nowrap data-[pinned=right]:border-l data-[pinned=right]:bg-elevated data-[pinned=right]:z-20',
            td: 'border-b border-default data-[pinned=right]:min-w-max data-[pinned=right]:whitespace-nowrap data-[pinned=right]:border-l data-[pinned=right]:bg-default data-[pinned=right]:z-10',
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
        :schema="z.object({ role: projectRoleSchema })"
        :state="editRoleState"
        class="space-y-4"
        :disabled="editRoleLoading"
        @submit="submitRole"
      >
        <UFormField name="role" label="Project role" required>
          <USelect
            v-model="editRoleState.role"
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

  <UModal v-model:open="editAccountOpen" title="Edit account">
    <template #body>
      <UForm
        :schema="accountSchema"
        :state="accountState"
        class="space-y-4"
        :disabled="editAccountLoading"
        @submit="submitAccount"
      >
        <UFormField name="name" label="Name" required>
          <UInput v-model="accountState.name" class="w-full" />
        </UFormField>
        <UFormField name="email" label="Email" required>
          <UInput v-model="accountState.email" type="email" class="w-full" />
        </UFormField>
        <UFormField name="password" label="New password">
          <UInput v-model="accountState.password" type="password" class="w-full" />
        </UFormField>
        <UFormField name="password_confirmation" label="Confirm new password">
          <UInput v-model="accountState.password_confirmation" type="password" class="w-full" />
        </UFormField>
        <div class="flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="subtle"
            @click="editAccountOpen = false"
          />
          <UButton label="Save account" type="submit" :loading="editAccountLoading" />
        </div>
      </UForm>
    </template>
  </UModal>

  <ConfirmDeleteModal
    v-model:open="deleteAccountOpen"
    :title="`Delete ${selectedUser?.name ?? 'user'} account`"
    description="This permanently deletes the global account and its memberships."
    :loading="deleteAccountLoading"
    @confirm="confirmDeleteAccount"
  />
</template>
