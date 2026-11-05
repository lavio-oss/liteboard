<script setup lang="ts">
import type { NavigationMenuItem, TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useTenantContext } from '~/composables/useTenantContext'
import type { PermissionDefinition, Role, RoleScope } from '~/types/auth'

definePageMeta({
  middleware: 'auth'
})

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
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
    header: sortableHeader('Applies to'),
    cell: ({ row }) => h(UBadge, { color: row.original.scope === 'tenant' ? 'warning' : 'info', variant: 'subtle', class: 'capitalize' }, () => row.original.scope)
  },
  {
    id: 'type',
    accessorFn: role => role.is_system ? 'System' : 'Custom',
    header: sortableHeader('Type')
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
    header: 'Actions',
    meta: { class: { th: 'text-center', td: 'text-center' } },
    cell: ({ row }) => h('div', { class: 'flex justify-center gap-1' }, [
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

const resourceIcons: Record<string, string> = {
  tenant: 'i-lucide-building-2',
  user: 'i-lucide-users',
  role: 'i-lucide-shield-check',
  project: 'i-lucide-folder-kanban',
  system: 'i-lucide-server-cog',
  device: 'i-lucide-cpu',
  asset: 'i-lucide-boxes',
  device_profile: 'i-lucide-file-sliders',
  alert_rule: 'i-lucide-bell-ring',
  alert: 'i-lucide-triangle-alert',
  webhook: 'i-lucide-webhook'
}
const resourceIcon = (resource: string): string => resourceIcons[resource] ?? 'i-lucide-shield'

const permissionSearch = ref('')
const permissionTab = ref('all')
const selectedPermissionKeys = computed(() => new Set(selectedRole.value?.is_owner
  ? assignablePermissions.value.map(permission => permission.key)
  : draftPermissions.value))

watch(permissionsOpen, (isOpen) => {
  if (isOpen) {
    permissionSearch.value = ''
    permissionTab.value = 'all'
  }
})

const permissionTabs = computed<NavigationMenuItem[][]>(() => [[
  { label: 'All', icon: 'i-lucide-layout-grid', active: permissionTab.value === 'all', onSelect: () => { permissionTab.value = 'all' } },
  ...permissionColumns.value.map(({ resource }) => ({
    label: resource.replace('_', ' '),
    icon: resourceIcon(resource),
    class: 'capitalize',
    active: permissionTab.value === resource,
    onSelect: () => { permissionTab.value = resource }
  }))
]])

const visiblePermissionGroups = computed(() => {
  const needle = permissionSearch.value.trim().toLocaleLowerCase()

  return permissionColumns.value
    .filter(({ resource }) => permissionTab.value === 'all' || permissionTab.value === resource)
    .map(({ resource, permissions }) => ({
      resource,
      permissions: permissions.filter(permission => permission.key.toLocaleLowerCase().includes(needle))
    }))
    .filter(group => group.permissions.length > 0)
})

function isGroupFullySelected(permissions: PermissionDefinition[]): boolean {
  return permissions.every(permission => selectedPermissionKeys.value.has(permission.key))
}

const visiblePermissions = computed(() => visiblePermissionGroups.value.flatMap(group => group.permissions))
const allVisibleSelected = computed(() => isGroupFullySelected(visiblePermissions.value))

function toggleVisiblePermissions(): void {
  toggleColumn(visiblePermissions.value, !allVisibleSelected.value)
}

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

        <div class="flex items-center justify-between gap-2">
          <UInput
            v-model="permissionSearch"
            icon="i-lucide-search"
            placeholder="Search permission..."
            class="w-64"
          />
          <UButton
            :icon="allVisibleSelected ? 'i-lucide-list-x' : 'i-lucide-list-checks'"
            :label="allVisibleSelected ? 'Deselect all' : 'Select all'"
            color="neutral"
            variant="subtle"
            class="shrink-0"
            :disabled="!isEditable || visiblePermissionGroups.length === 0"
            @click="toggleVisiblePermissions"
          />
        </div>

        <UNavigationMenu :items="permissionTabs" highlight class="-mx-1 overflow-x-auto" />

        <div class="flex max-h-[55vh] flex-col gap-5 overflow-y-auto">
          <section v-for="group in visiblePermissionGroups" :key="group.resource">
            <div class="mb-2 flex items-center justify-between border-b border-default pb-2">
              <h3 class="flex items-center gap-2 text-sm font-semibold capitalize text-highlighted">
                <UIcon :name="resourceIcon(group.resource)" class="size-4 text-muted" />
                {{ group.resource.replace('_', ' ') }}
              </h3>
              <UButton
                :label="isGroupFullySelected(group.permissions) ? 'Deselect group' : 'Select group'"
                color="neutral"
                variant="ghost"
                size="xs"
                :disabled="!isEditable"
                @click="toggleColumn(group.permissions, !isGroupFullySelected(group.permissions))"
              />
            </div>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
              <label
                v-for="permission in group.permissions"
                :key="permission.key"
                class="flex items-start gap-3 rounded-lg border p-3 transition-colors"
                :class="[
                  selectedPermissionKeys.has(permission.key) ? 'border-primary/50 bg-primary/10' : 'border-default',
                  isEditable && !selectedRole?.locked_permissions.includes(permission.key) ? 'cursor-pointer' : 'cursor-not-allowed opacity-80'
                ]"
              >
                <UCheckbox
                  :model-value="selectedPermissionKeys.has(permission.key)"
                  :disabled="!isEditable || selectedRole?.locked_permissions.includes(permission.key)"
                  @update:model-value="togglePermission(permission.key, $event === true)"
                />
                <span class="min-w-0">
                  <span class="block truncate text-sm font-medium capitalize text-highlighted">
                    {{ permission.key.slice(group.resource.length + 1).replace('.', ' ') }}
                  </span>
                  <span class="block truncate text-xs text-muted">{{ permission.key }}</span>
                </span>
              </label>
            </div>
          </section>
          <p v-if="visiblePermissionGroups.length === 0" class="py-6 text-center text-sm text-muted">
            No matching permission.
          </p>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center gap-2">
        <span class="text-sm text-muted">
          Selected: {{ selectedPermissionKeys.size }} / {{ assignablePermissions.length }} permissions
        </span>
        <UButton
          label="Cancel"
          color="neutral"
          variant="subtle"
          class="ml-auto"
          @click="permissionsOpen = false"
        />
        <UButton
          v-if="isEditable"
          label="Save"
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
            placeholder="Enter role name"
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
            placeholder="Choose who the role applies to"
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
