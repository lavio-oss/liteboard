<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useTenantContext } from '~/composables/useTenantContext'
import type { TenantRole, TenantUser, TenantUserCreateInput, TenantUserUpdateInput } from '~/types/auth'

definePageMeta({
  middleware: 'auth'
})

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const toast = useToast()
const table = useTemplateRef('table')
const { user } = useAuth()
const { activeTenant, canManageActiveTenant, loadTenants } = useTenantContext()
const { createTenantUser, deleteTenantUser, loadTenantUsers, updateTenantUser } = useAdmin()
await loadTenants()

const tenantId = computed(() => activeTenant.value?.id ?? null)
const addRequested = useState('admin:add-requested', () => false)
const users = ref<TenantUser[]>([])
const search = ref('')
const roleFilter = ref<'all' | TenantRole>('all')
const columnVisibility = ref()
const columnPinning = ref({ right: ['actions'] })
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const editOpen = ref(false)
const editLoading = ref(false)
const editingUser = ref<TenantUser | null>(null)
const deleteOpen = ref(false)
const deleteLoading = ref(false)
const deletingUsers = ref<TenantUser[]>([])

const roleOptions = [
  { label: 'All roles', value: 'all' as const },
  { label: 'Owner', value: 'owner' as const },
  { label: 'Admin', value: 'admin' as const },
  { label: 'Member', value: 'member' as const }
]

const { withScreenLoading } = useScreenLoading()

async function resetFilters(): Promise<void> {
  search.value = ''
  roleFilter.value = 'all'
  pagination.value.pageIndex = 0
  await withScreenLoading(async () => {
    await reload()
  }, 400)
}

const filteredUsers = computed(() => {
  const query = search.value.toLowerCase()

  return users.value.filter((item) => {
    const matchesSearch = query === ''
      || item.name.toLowerCase().includes(query)
      || item.email.toLowerCase().includes(query)
    const matchesRole = roleFilter.value === 'all' || item.role === roleFilter.value

    return matchesSearch && matchesRole
  })
})
const canManage = canManageActiveTenant
const canDelete = computed(() => user.value?.system_role === 'platform_admin')
const userLoading = ref(false)

async function reload(): Promise<void> {
  if (tenantId.value === null || !canManage.value) {
    users.value = []
    return
  }

  userLoading.value = true
  try {
    users.value = await loadTenantUsers(tenantId.value)
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to load users', description: error.message, color: 'error' })
  } finally {
    userLoading.value = false
  }
}

function openCreate(): void {
  editingUser.value = null
  editOpen.value = true
}

function openEdit(item: TenantUser): void {
  editingUser.value = item
  editOpen.value = true
}

function openDelete(items: TenantUser[]): void {
  deletingUsers.value = items.filter(item => item.id !== user.value?.id)

  if (deletingUsers.value.length > 0) {
    deleteOpen.value = true
  }
}

async function createUser(payload: TenantUserCreateInput): Promise<void> {
  if (tenantId.value === null) {
    throw new Error('A tenant is required before creating a user.')
  }

  editLoading.value = true

  try {
    await createTenantUser(tenantId.value, payload)
    await reload()
    editOpen.value = false
    toast.add({ title: 'User created', description: 'The account has been added to the tenant.', color: 'success' })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to create user', description: error.message, color: 'error' })
  } finally {
    editLoading.value = false
  }
}

async function saveUser(payload: TenantUserUpdateInput): Promise<void> {
  if (tenantId.value === null || editingUser.value === null) {
    throw new Error('A tenant user is required before editing.')
  }

  editLoading.value = true

  try {
    await updateTenantUser(tenantId.value, editingUser.value.id, payload)
    await reload()
    editOpen.value = false
    toast.add({ title: 'User updated', description: 'The account has been saved.', color: 'success' })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to update user', description: error.message, color: 'error' })
  } finally {
    editLoading.value = false
  }
}

async function confirmDelete(): Promise<void> {
  if (tenantId.value === null || deletingUsers.value.length === 0) {
    throw new Error('A tenant user is required before deletion.')
  }

  deleteLoading.value = true

  try {
    await Promise.all(deletingUsers.value.map(item => deleteTenantUser(tenantId.value as string, item.id)))
    await reload()
    deleteOpen.value = false
    toast.add({ title: 'User deleted', description: 'The account has been deleted.', color: 'success' })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to delete user', description: error.message, color: 'error' })
  } finally {
    deleteLoading.value = false
  }
}

const columns: TableColumn<TenantUser>[] = [
  {
    id: 'sequence',
    header: 'No.',
    cell: ({ row }) => row.index + 1
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
    }
  },
  {
    accessorKey: 'role',
    header: 'Tenant role',
    cell: ({ row }) => h(UBadge, {
      color: row.original.role === 'owner' ? 'primary' : row.original.role === 'admin' ? 'success' : 'neutral',
      variant: 'subtle',
      class: 'capitalize'
    }, () => row.original.role)
  },
  {
    accessorKey: 'system_role',
    header: 'System role',
    cell: ({ row }) => row.original.system_role === 'platform_admin'
      ? h(UBadge, { color: 'warning', variant: 'subtle' }, () => 'Platform admin')
      : h('span', { class: 'text-muted' }, '—')
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, {
        'icon': 'i-lucide-pencil',
        'color': 'neutral',
        'variant': 'ghost',
        'aria-label': `Edit account for ${row.original.name}`,
        'onClick': () => openEdit(row.original)
      }),
      ...(canDelete.value && row.original.id !== user.value?.id
        ? [h(UButton, {
            'icon': 'i-lucide-trash-2',
            'color': 'error',
            'variant': 'ghost',
            'aria-label': `Delete account for ${row.original.name}`,
            'onClick': () => openDelete([row.original])
          })]
        : [])
    ])
  }
]

watch([tenantId, canManage], () => {
  void reload()
}, { immediate: true })

watch(addRequested, (requested) => {
  if (requested) {
    addRequested.value = false
    openCreate()
  }
})
</script>

<template>
  <UDashboardPanel id="users" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Users">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="canManage"
            label="Add user"
            icon="i-lucide-plus"
            @click="openCreate"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="!canManage"
          color="warning"
          variant="subtle"
          title="User management is restricted"
          description="Only tenant owners, tenant admins, and platform admins can manage users."
        />

        <template v-else>
          <div class="flex flex-wrap items-center justify-between gap-1.5">
            <UInput
              v-model="search"
              class="w-64"
              icon="i-lucide-search"
              placeholder="Filter users..."
            />
            <div class="flex flex-wrap items-center gap-1.5">
              <USelect v-model="roleFilter" :items="roleOptions" class="min-w-32" />
              <UDropdownMenu
                :items="[
                  table?.tableApi
                    ?.getAllColumns()
                    .filter(column => column.getCanHide())
                    .map(column => ({
                      label: upperFirst(column.id),
                      type: 'checkbox' as const,
                      checked: column.getIsVisible(),
                      onUpdateChecked(checked: boolean) {
                        table?.tableApi?.getColumn(column.id)?.toggleVisibility(checked)
                      },
                      onSelect(event?: Event) {
                        event?.preventDefault()
                      }
                    })) ?? []
                ]"
                :content="{ align: 'end' }"
              >
                <UButton
                  label="Display"
                  color="neutral"
                  variant="outline"
                  trailing-icon="i-lucide-settings-2"
                />
              </UDropdownMenu>

              <ResetFiltersButton :loading="userLoading" @click="resetFilters" />
            </div>
          </div>

          <UTable
            ref="table"
            v-model:column-visibility="columnVisibility"
            v-model:column-pinning="columnPinning"
            v-model:pagination="pagination"
            :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
            class="shrink-0"
            :data="filteredUsers"
            :columns="columns"
            :ui="{
              base: 'border-separate border-spacing-0',
              thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
              tbody: '[&>tr]:last:[&>td]:border-b-0',
              th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r data-[pinned=right]:min-w-max data-[pinned=right]:whitespace-nowrap data-[pinned=right]:border-l data-[pinned=right]:bg-elevated data-[pinned=right]:z-20',
              td: 'border-b border-default data-[pinned=right]:min-w-max data-[pinned=right]:whitespace-nowrap data-[pinned=right]:border-l data-[pinned=right]:bg-default data-[pinned=right]:z-10',
              separator: 'h-0'
            }"
          />

          <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
            <div class="text-sm text-muted">
              {{ filteredUsers.length }} row(s).
            </div>
            <UPagination
              :default-page="pagination.pageIndex + 1"
              :items-per-page="pagination.pageSize"
              :total="filteredUsers.length"
              @update:page="(page: number) => table?.tableApi?.setPageIndex(page - 1)"
            />
          </div>
        </template>
      </div>

      <AdminTenantUserFormModal
        v-model:open="editOpen"
        :user="editingUser"
        :loading="editLoading"
        @create="createUser"
        @update="saveUser"
      />

      <ConfirmDeleteModal
        v-model:open="deleteOpen"
        :title="`Delete ${deletingUsers.length} user${deletingUsers.length === 1 ? '' : 's'}`"
        description="This permanently deletes the selected global account(s) and their memberships."
        :loading="deleteLoading"
        @confirm="confirmDelete"
      />
    </template>
  </UDashboardPanel>
</template>
