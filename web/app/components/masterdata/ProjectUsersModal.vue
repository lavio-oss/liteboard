<script setup lang="ts">
import type { TableColumn, FormSubmitEvent } from '@nuxt/ui'
import { getPaginationRowModel } from '@tanstack/table-core'
import * as z from 'zod'
import { ApiError } from '~/composables/useApi'
import { useMasterdata } from '~/composables/useMasterdata'
import type { AvailableProjectUser, Project, ProjectRole, ProjectUser } from '~/types/masterdata'
import { projectRoleSchema } from '~/types/masterdata'

const props = withDefaults(defineProps<{
  tenantId: number | null
  project: Project | null
  canDeleteAccount?: boolean
}>(), {
  canDeleteAccount: false
})

const open = defineModel<boolean>('open', { required: true })

type ExistingForm = {
  user_id: number | undefined
  role: ProjectRole
}

type NewAccountForm = {
  name: string
  email: string
  password: string
  password_confirmation: string
  role: ProjectRole
}

type AccountForm = {
  name: string
  email: string
  password: string
  password_confirmation: string
}

const UAvatar = resolveComponent('UAvatar')
const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const toast = useToast()
const table = useTemplateRef('table')
const {
  attachProjectUser,
  createProjectUserAccount,
  deleteTenantUser,
  loadAvailableProjectUsers,
  loadProjectUsers,
  removeProjectUser,
  updateProjectUser,
  updateTenantUser
} = useMasterdata()

const users = ref<ProjectUser[]>([])
const availableUsers = ref<AvailableProjectUser[]>([])
const loading = ref(false)
const search = ref('')
const roleFilter = ref<'all' | ProjectRole>('all')
const columnVisibility = ref()
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const rowSelection = ref<Record<string, boolean>>({})
const addOpen = ref(false)
const addMode = ref<'existing' | 'new'>('existing')
const addLoading = ref(false)
const editRoleOpen = ref(false)
const editRoleLoading = ref(false)
const editAccountOpen = ref(false)
const editAccountLoading = ref(false)
const removeOpen = ref(false)
const removeLoading = ref(false)
const deleteAccountOpen = ref(false)
const deleteAccountLoading = ref(false)
const selectedUser = ref<ProjectUser | null>(null)
const usersToRemove = ref<ProjectUser[]>([])

const existingSchema = z.object({
  user_id: z.number().int().positive('Select a user'),
  role: projectRoleSchema
})
const newAccountSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  email: z.string().email('Enter a valid email address'),
  password: z.string().min(8, 'Password must be at least 8 characters'),
  password_confirmation: z.string().min(8, 'Confirm the password'),
  role: projectRoleSchema
}).refine(value => value.password === value.password_confirmation, {
  message: 'Passwords do not match',
  path: ['password_confirmation']
})
const accountSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  email: z.string().email('Enter a valid email address'),
  password: z.string().refine(value => value === '' || value.length >= 8, 'Password must be at least 8 characters'),
  password_confirmation: z.string()
}).refine(value => value.password === value.password_confirmation, {
  message: 'Passwords do not match',
  path: ['password_confirmation']
})

const existingState = reactive<ExistingForm>({
  user_id: undefined,
  role: 'viewer'
})
const newAccountState = reactive<NewAccountForm>({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  role: 'viewer'
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
const roleFilterOptions = [{ label: 'All roles', value: 'all' as const }, ...roleOptions]
const title = computed(() => props.project === null ? 'Project users' : `${props.project.name} users`)
const filteredUsers = computed(() => {
  const query = search.value.toLowerCase()

  return users.value.filter((user) => {
    const matchesSearch = query === ''
      || user.name.toLowerCase().includes(query)
      || user.email.toLowerCase().includes(query)
    const matchesRole = roleFilter.value === 'all' || user.role === roleFilter.value

    return matchesSearch && matchesRole
  })
})
const selectedUsers = computed(() => users.value.filter(user => rowSelection.value[String(user.id)] === true))
const availableOptions = computed(() => availableUsers.value.map(user => ({
  label: `${user.name} (${user.email})`,
  value: user.id
})))

function roleLabel(role: ProjectRole): string {
  return roleOptions.find(option => option.value === role)?.label ?? role
}

async function reload(): Promise<void> {
  if (!open.value || props.tenantId === null || props.project === null) {
    return
  }

  loading.value = true

  try {
    const [projectUsers, available] = await Promise.all([
      loadProjectUsers(props.tenantId, props.project.id),
      loadAvailableProjectUsers(props.tenantId, props.project.id)
    ])
    users.value = projectUsers
    availableUsers.value = available
    rowSelection.value = {}
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to load project users', description: error.message, color: 'error' })
  } finally {
    loading.value = false
  }
}

function resetAddState(): void {
  existingState.user_id = undefined
  existingState.role = 'viewer'
  newAccountState.name = ''
  newAccountState.email = ''
  newAccountState.password = ''
  newAccountState.password_confirmation = ''
  newAccountState.role = 'viewer'
}

function openAdd(): void {
  resetAddState()
  addMode.value = 'existing'
  addOpen.value = true
}

function openRoleEdit(user: ProjectUser): void {
  selectedUser.value = user
  editRoleOpen.value = true
}

function openAccountEdit(user: ProjectUser): void {
  selectedUser.value = user
  accountState.name = user.name
  accountState.email = user.email
  accountState.password = ''
  accountState.password_confirmation = ''
  editAccountOpen.value = true
}

function openRemove(usersForRemoval: ProjectUser[]): void {
  usersToRemove.value = usersForRemoval
  removeOpen.value = true
}

async function submitExisting(event: FormSubmitEvent<ExistingForm>): Promise<void> {
  if (props.tenantId === null || props.project === null || event.data.user_id === undefined) {
    throw new Error('A project and user are required before assignment.')
  }

  addLoading.value = true

  try {
    await attachProjectUser(props.tenantId, props.project.id, {
      user_id: event.data.user_id,
      role: event.data.role
    })
    await reload()
    addOpen.value = false
    toast.add({ title: 'User added', description: 'The user has been assigned to the project.', color: 'success' })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to add user', description: error.message, color: 'error' })
  } finally {
    addLoading.value = false
  }
}

async function submitNewAccount(event: FormSubmitEvent<NewAccountForm>): Promise<void> {
  if (props.tenantId === null || props.project === null) {
    throw new Error('A project is required before creating a user.')
  }

  addLoading.value = true

  try {
    await createProjectUserAccount(props.tenantId, props.project.id, event.data)
    await reload()
    addOpen.value = false
    toast.add({ title: 'User created', description: 'The account has been assigned to the project.', color: 'success' })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to create user', description: error.message, color: 'error' })
  } finally {
    addLoading.value = false
  }
}

async function submitRole(event: FormSubmitEvent<{ role: ProjectRole }>): Promise<void> {
  if (props.tenantId === null || props.project === null || selectedUser.value === null) {
    throw new Error('A project user is required before updating the role.')
  }

  editRoleLoading.value = true

  try {
    await updateProjectUser(props.tenantId, props.project.id, selectedUser.value.id, event.data.role)
    await reload()
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
    await reload()
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

async function confirmRemove(): Promise<void> {
  if (props.tenantId === null || props.project === null || usersToRemove.value.length === 0) {
    throw new Error('A project user is required before removal.')
  }

  removeLoading.value = true

  try {
    await Promise.all(usersToRemove.value.map(user => removeProjectUser(props.tenantId as number, props.project?.id as number, user.id)))
    await reload()
    removeOpen.value = false
    toast.add({ title: 'User removed', description: 'The user has been removed from the project.', color: 'success' })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({ title: 'Unable to remove user', description: error.message, color: 'error' })
  } finally {
    removeLoading.value = false
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

const columns: TableColumn<ProjectUser>[] = [
  {
    id: 'select',
    header: ({ table }) => h(UCheckbox, {
      'modelValue': table.getIsSomePageRowsSelected() ? 'indeterminate' : table.getIsAllPageRowsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => table.toggleAllPageRowsSelected(!!value),
      'ariaLabel': 'Select all users'
    }),
    cell: ({ row }) => h(UCheckbox, {
      'modelValue': row.getIsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(!!value),
      'ariaLabel': `Select ${row.original.name}`
    })
  },
  {
    accessorKey: 'name',
    header: 'Name',
    cell: ({ row }) => h('div', { class: 'flex items-center gap-3' }, [
      h(UAvatar, { text: row.original.name.slice(0, 1).toUpperCase(), size: 'md' }),
      h('div', undefined, [
        h('p', { class: 'font-medium text-highlighted' }, row.original.name),
        h('p', { class: 'text-muted' }, row.original.email)
      ])
    ])
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
    header: 'Project role',
    cell: ({ row }) => h('div', { class: 'flex items-center gap-1.5' }, [
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
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, {
        'icon': 'i-lucide-user-pen',
        'color': 'neutral',
        'variant': 'ghost',
        'aria-label': `Edit account for ${row.original.name}`,
        'onClick': () => openAccountEdit(row.original)
      }),
      h(UButton, {
        'icon': 'i-lucide-user-minus',
        'color': 'error',
        'variant': 'ghost',
        'aria-label': `Remove ${row.original.name} from project`,
        'onClick': () => openRemove([row.original])
      }),
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
            class="max-w-sm"
          />
          <div class="flex flex-wrap items-center gap-2">
            <USelect v-model="roleFilter" :items="roleFilterOptions" class="min-w-36" />
            <UButton
              v-if="selectedUsers.length > 0"
              label="Remove selected"
              color="error"
              variant="subtle"
              icon="i-lucide-user-minus"
              @click="openRemove(selectedUsers)"
            />
            <UButton label="Add user" icon="i-lucide-user-plus" @click="openAdd" />
          </div>
        </div>

        <UTable
          ref="table"
          v-model:column-visibility="columnVisibility"
          v-model:row-selection="rowSelection"
          v-model:pagination="pagination"
          :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
          :data="filteredUsers"
          :columns="columns"
          :loading="loading"
          :ui="{
            base: 'table-fixed border-separate border-spacing-0',
            thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
            td: 'border-b border-default',
            separator: 'h-0'
          }"
        />

        <div class="flex items-center justify-between gap-3 border-t border-default pt-4">
          <div class="text-sm text-muted">
            {{ selectedUsers.length }} selected of {{ filteredUsers.length }} user(s).
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

  <UModal v-model:open="addOpen" title="Add project user">
    <template #body>
      <div class="flex flex-col gap-4">
        <USelect
          v-model="addMode"
          :items="[
            { label: 'Existing tenant user', value: 'existing' },
            { label: 'Create new account', value: 'new' }
          ]"
        />

        <UForm
          v-if="addMode === 'existing'"
          :schema="existingSchema"
          :state="existingState"
          :disabled="addLoading"
          class="space-y-4"
          @submit="submitExisting"
        >
          <UFormField name="user_id" label="User" required>
            <USelect
              v-model="existingState.user_id"
              :items="availableOptions"
              class="w-full"
              placeholder="Select a tenant user"
            />
          </UFormField>
          <UFormField name="role" label="Project role" required>
            <USelect v-model="existingState.role" :items="roleOptions" class="w-full" />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="subtle"
              @click="addOpen = false"
            />
            <UButton label="Add user" type="submit" :loading="addLoading" />
          </div>
        </UForm>

        <UForm
          v-else
          :schema="newAccountSchema"
          :state="newAccountState"
          :disabled="addLoading"
          class="space-y-4"
          @submit="submitNewAccount"
        >
          <UFormField name="name" label="Name" required>
            <UInput v-model="newAccountState.name" class="w-full" />
          </UFormField>
          <UFormField name="email" label="Email" required>
            <UInput v-model="newAccountState.email" type="email" class="w-full" />
          </UFormField>
          <UFormField name="password" label="Password" required>
            <UInput v-model="newAccountState.password" type="password" class="w-full" />
          </UFormField>
          <UFormField name="password_confirmation" label="Confirm password" required>
            <UInput v-model="newAccountState.password_confirmation" type="password" class="w-full" />
          </UFormField>
          <UFormField name="role" label="Project role" required>
            <USelect v-model="newAccountState.role" :items="roleOptions" class="w-full" />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="subtle"
              @click="addOpen = false"
            />
            <UButton label="Create user" type="submit" :loading="addLoading" />
          </div>
        </UForm>
      </div>
    </template>
  </UModal>

  <UModal v-model:open="editRoleOpen" title="Edit project role">
    <template #body>
      <UForm
        :schema="z.object({ role: projectRoleSchema })"
        :state="selectedUser ?? { role: 'viewer' }"
        class="space-y-4"
        :disabled="editRoleLoading"
        @submit="submitRole"
      >
        <UFormField name="role" label="Project role" required>
          <USelect
            v-if="selectedUser !== null"
            v-model="selectedUser.role"
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
    v-model:open="removeOpen"
    :title="`Remove ${usersToRemove.length} user${usersToRemove.length === 1 ? '' : 's'} from project`"
    description="The account will remain in the tenant."
    :loading="removeLoading"
    @confirm="confirmRemove"
  />

  <ConfirmDeleteModal
    v-model:open="deleteAccountOpen"
    :title="`Delete ${selectedUser?.name ?? 'user'} account`"
    description="This permanently deletes the global account and its memberships."
    :loading="deleteAccountLoading"
    @confirm="confirmDeleteAccount"
  />
</template>
