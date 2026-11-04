<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useTenantContext } from '~/composables/useTenantContext'
import type { PermissionDefinition, Role, RoleScope } from '~/types/auth'

definePageMeta({
  middleware: 'auth'
})

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const toast = useToast()
const { activeTenant, loadTenants } = useTenantContext()
const { createRole, deleteRole, loadPermissionDefinitions, loadRoles, updateRole } = useAdmin()
await loadTenants()

const tenantId = computed(() => activeTenant.value?.id ?? null)
const canManage = computed(() => activeTenant.value?.permissions.includes('role.manage') === true)
const tableUi = {
  base: 'border-separate border-spacing-0',
  thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
  tbody: '[&>tr]:last:[&>td]:border-b-0',
  th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
  td: 'border-b border-default',
  separator: 'h-0'
}

const { data, status, refresh } = await useAsyncData('admin-roles', async () => {
  if (tenantId.value === null) {
    return { roles: [] as Role[], permissions: [] as PermissionDefinition[] }
  }

  const [roles, permissions] = await Promise.all([
    loadRoles(tenantId.value),
    loadPermissionDefinitions(tenantId.value)
  ])

  return { roles, permissions }
}, { watch: [tenantId] })

const roles = computed(() => data.value?.roles ?? [])
const search = ref('')
const scopeFilter = ref<'all' | RoleScope>('all')
const typeFilter = ref<'all' | 'system' | 'custom'>('all')
const filteredRoles = computed(() => roles.value.filter(role =>
  role.name.toLowerCase().includes(search.value.toLowerCase())
  && (scopeFilter.value === 'all' || role.scope === scopeFilter.value)
  && (typeFilter.value === 'all' || role.is_system === (typeFilter.value === 'system'))
))
const sortableHeader = useSortableHeader()
const permissionsOpen = ref(false)
const deleteOpen = ref(false)
const selectedRoleId = ref<string | null>(null)
const selectedRole = computed(() => roles.value.find(role => role.id === selectedRoleId.value) ?? null)
const draftPermissions = ref<string[]>([])
const saving = ref(false)

watch(selectedRole, (role) => {
  draftPermissions.value = role === null ? [] : [...role.permissions]
}, { immediate: true })

async function resetFilters(): Promise<void> {
  search.value = ''
  scopeFilter.value = 'all'
  typeFilter.value = 'all'
  await refresh()
}

function openRole(role: Role): void {
  selectedRoleId.value = role.id
  permissionsOpen.value = true
}

const roleColumns: TableColumn<Role>[] = [
  {
    accessorKey: 'name',
    header: 'Name',
    cell: ({ row }) => h('span', { class: 'font-medium text-highlighted' }, row.original.name)
  },
  {
    accessorKey: 'scope',
    header: 'Applies to',
    cell: ({ row }) => h(UBadge, { color: row.original.scope === 'tenant' ? 'warning' : 'info', variant: 'subtle', class: 'capitalize' }, () => row.original.scope)
  },
  {
    id: 'type',
    header: 'Type',
    cell: ({ row }) => row.original.is_system ? 'System' : 'Custom'
  },
  {
    id: 'permissions',
    header: 'Permissions',
    cell: ({ row }) => row.original.permissions.length
  },
  {
    id: 'created_by',
    header: 'Created by',
    cell: ({ row }) => row.original.created_by?.name ?? 'System'
  },
  {
    accessorKey: 'created_at',
    header: sortableHeader('Created at'),
    cell: ({ row }) => formatDateTime(row.original.created_at)
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, {
        'icon': 'i-lucide-shield-check',
        'color': 'neutral',
        'variant': 'ghost',
        'aria-label': `Permissions of ${row.original.name}`,
        'onClick': (event: Event) => {
          event.stopPropagation()
          openRole(row.original)
        }
      }),
      ...(canManage.value && !row.original.is_system
        ? [h(UButton, {
            'icon': 'i-lucide-trash-2',
            'color': 'error',
            'variant': 'ghost',
            'aria-label': `Delete ${row.original.name}`,
            'onClick': (event: Event) => {
              event.stopPropagation()
              selectedRoleId.value = row.original.id
              deleteOpen.value = true
            }
          })]
        : [])
    ])
  }
]

// Tenant roles may hold every permission; project roles only project-scoped ones.
const assignablePermissions = computed(() => (data.value?.permissions ?? []).filter(permission =>
  selectedRole.value?.scope === 'tenant' || permission.scope === 'project'
))
// One column per resource: "device.write" lands in the "device" column as "write".
const permissionColumns = computed(() => {
  const columns = new Map<string, PermissionDefinition[]>()

  for (const permission of assignablePermissions.value) {
    const [resource = permission.key] = permission.key.split('.')
    columns.set(resource, [...(columns.get(resource) ?? []), permission])
  }

  return [...columns].map(([resource, permissions]) => ({ resource, permissions }))
})
const isEditable = computed(() => canManage.value && selectedRole.value !== null && !selectedRole.value.is_owner)
const isDirty = computed(() => selectedRole.value !== null
  && [...draftPermissions.value].sort().join() !== [...selectedRole.value.permissions].sort().join())

function togglePermission(key: string, checked: boolean): void {
  draftPermissions.value = checked
    ? [...draftPermissions.value, key]
    : draftPermissions.value.filter(permission => permission !== key)
}

// A column header ticks or unticks every permission of its resource; permissions the role must keep stay ticked.
function toggleColumn(permissions: PermissionDefinition[], checked: boolean): void {
  const keys = permissions.map(permission => permission.key)
  const locked = selectedRole.value?.locked_permissions ?? []

  draftPermissions.value = checked
    ? [...new Set([...draftPermissions.value, ...keys])]
    : draftPermissions.value.filter(key => !keys.includes(key) || locked.includes(key))
}

// Rows are slots: row N holds the Nth permission of every resource column.
const permissionRows = computed(() => Array.from(
  { length: Math.max(0, ...permissionColumns.value.map(column => column.permissions.length)) },
  (_, index) => index
))
const permissionTableColumns = computed<TableColumn<number>[]>(() => {
  const selected = new Set(selectedRole.value?.is_owner ? assignablePermissions.value.map(permission => permission.key) : draftPermissions.value)
  const locked = selectedRole.value?.locked_permissions ?? []

  return permissionColumns.value.map(column => ({
    id: column.resource,
    header: () => {
      const checkedCount = column.permissions.filter(permission => selected.has(permission.key)).length

      return h('div', { class: 'flex items-center gap-2 capitalize' }, [
        h(UCheckbox, {
          'modelValue': checkedCount === column.permissions.length ? true : checkedCount === 0 ? false : 'indeterminate',
          'disabled': !isEditable.value,
          'onUpdate:modelValue': (checked: boolean | 'indeterminate') => toggleColumn(column.permissions, checked === true)
        }),
        column.resource.replace('_', ' ')
      ])
    },
    cell: ({ row }) => {
      const permission = column.permissions[row.original]

      return permission === undefined
        ? null
        : h(UCheckbox, {
            'label': permission.key.slice(column.resource.length + 1),
            'modelValue': selected.has(permission.key),
            'disabled': !isEditable.value || locked.includes(permission.key),
            'onUpdate:modelValue': (checked: boolean | 'indeterminate') => togglePermission(permission.key, checked === true)
          })
    }
  }))
})

function notifyError(error: unknown, title: string): void {
  if (!(error instanceof ApiError)) {
    throw error
  }

  toast.add({ title, description: Object.values(error.errors).flat()[0] ?? error.message, color: 'error' })
}

async function savePermissions(): Promise<void> {
  if (tenantId.value === null || selectedRole.value === null) {
    throw new Error('A role is required before saving.')
  }

  saving.value = true

  try {
    await updateRole(tenantId.value, selectedRole.value.id, { permissions: draftPermissions.value })
    await Promise.all([refresh(), loadTenants(true)])
    permissionsOpen.value = false
    toast.add({ title: 'Role saved', description: 'The new permissions apply immediately.', color: 'success' })
  } catch (error: unknown) {
    notifyError(error, 'Unable to save role')
  } finally {
    saving.value = false
  }
}

const createOpen = ref(false)
const createState = reactive<{ name: string, scope: RoleScope }>({ name: '', scope: 'project' })

async function submitCreate(): Promise<void> {
  if (tenantId.value === null) {
    throw new Error('A tenant is required before creating a role.')
  }

  saving.value = true

  try {
    const role = await createRole(tenantId.value, { name: createState.name, scope: createState.scope, permissions: [] })
    await refresh()
    createOpen.value = false
    createState.name = ''
    openRole(role)
  } catch (error: unknown) {
    notifyError(error, 'Unable to create role')
  } finally {
    saving.value = false
  }
}

async function confirmDelete(): Promise<void> {
  if (tenantId.value === null || selectedRole.value === null) {
    throw new Error('A role is required before deletion.')
  }

  saving.value = true

  try {
    await deleteRole(tenantId.value, selectedRole.value.id)
    deleteOpen.value = false
    permissionsOpen.value = false
    await refresh()
  } catch (error: unknown) {
    notifyError(error, 'Unable to delete role')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="roles" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Roles">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="canManage"
            label="Add role"
            icon="i-lucide-plus"
            @click="createOpen = true"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-wrap items-center justify-between gap-1.5">
        <UInput
          v-model="search"
          class="w-64"
          icon="i-lucide-search"
          placeholder="Search roles..."
        />
        <div class="flex flex-wrap items-center gap-1.5">
          <USelect
            v-model="scopeFilter"
            :items="[{ label: 'All scopes', value: 'all' }, { label: 'Tenant', value: 'tenant' }, { label: 'Project', value: 'project' }]"
            class="min-w-32"
          />
          <USelect
            v-model="typeFilter"
            :items="[{ label: 'All types', value: 'all' }, { label: 'System', value: 'system' }, { label: 'Custom', value: 'custom' }]"
            class="min-w-32"
          />
          <ResetFiltersButton
            :loading="status === 'pending'"
            @click="resetFilters"
          />
        </div>
      </div>

      <AppTable
        :data="filteredRoles"
        :columns="roleColumns"
        :loading="status === 'pending'"
        :ui="tableUi"
        :meta="{ class: { tr: 'cursor-pointer' } }"
        class="shrink-0"
        @select="(_event: Event, row: { original: Role }) => openRole(row.original)"
      />
    </template>
  </UDashboardPanel>

  <UModal
    v-model:open="permissionsOpen"
    :title="selectedRole === null ? 'Permissions' : `${selectedRole.name} permissions`"
    :ui="{ content: 'sm:max-w-6xl' }"
  >
    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="!canManage"
          color="neutral"
          variant="subtle"
          title="Your role does not include role management"
        />

        <div class="overflow-x-auto">
          <AppTable
            :data="permissionRows"
            :columns="permissionTableColumns"
            :ui="tableUi"
            class="min-w-max"
          />
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center gap-2">
        <UButton
          v-if="isEditable && selectedRole !== null && !selectedRole.is_system"
          label="Delete role"
          color="error"
          variant="subtle"
          @click="deleteOpen = true"
        />
        <UButton
          label="Close"
          color="neutral"
          variant="subtle"
          class="ml-auto"
          @click="permissionsOpen = false"
        />
        <UButton
          v-if="isEditable"
          label="Save permissions"
          :disabled="!isDirty"
          :loading="saving"
          @click="savePermissions"
        />
      </div>
    </template>
  </UModal>

  <UModal
    v-model:open="createOpen"
    title="Add role"
  >
    <template #body>
      <UForm
        :state="createState"
        class="space-y-4"
        :disabled="saving"
        @submit="submitCreate"
      >
        <UFormField
          name="name"
          label="Name"
          required
        >
          <UInput
            v-model="createState.name"
            class="w-full"
          />
        </UFormField>
        <UFormField
          name="scope"
          label="Applies to"
          required
        >
          <USelect
            v-model="createState.scope"
            :items="[{ label: 'Project members', value: 'project' }, { label: 'Tenant members', value: 'tenant' }]"
            class="w-full"
          />
        </UFormField>
        <div class="flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="subtle"
            @click="createOpen = false"
          />
          <UButton
            label="Create"
            type="submit"
            :loading="saving"
          />
        </div>
      </UForm>
    </template>
  </UModal>

  <ConfirmDeleteModal
    v-model:open="deleteOpen"
    :title="`Delete ${selectedRole?.name ?? 'role'}`"
    description="A role can only be deleted once nobody holds it."
    :loading="saving"
    @confirm="confirmDelete"
  />
</template>
