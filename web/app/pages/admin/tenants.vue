<script setup lang="ts">
import { z } from 'zod'
import type { TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import { ApiError, useApi } from '~/composables/useApi'
import {
  tenantCollectionResponseSchema,
  tenantResponseSchema
} from '~/types/auth'
import type { Tenant } from '~/types/auth'

definePageMeta({
  middleware: 'auth'
})

type TenantPayload = {
  name: string
  code: string
  status: 'active' | 'inactive'
}

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const api = useApi()
const toast = useToast()
const table = useTemplateRef('table')
const { user, load } = useAuth()
const search = ref('')
const statusFilter = ref<'all' | Tenant['status']>('all')
const columnVisibility = ref()
const pagination = ref({
  pageIndex: 0,
  pageSize: 10
})
const formOpen = ref(false)
const formLoading = ref(false)
const formErrors = ref<Record<string, string[]>>({})
const editingTenant = ref<Tenant | null>(null)
const deleteOpen = ref(false)
const deleteLoading = ref(false)
const deletingTenant = ref<Tenant | null>(null)
const { data: response, status, error, refresh } = await useAsyncData('tenants', () =>
  api.request('/api/tenants', tenantCollectionResponseSchema)
)

const { withScreenLoading } = useScreenLoading()

async function resetFilters(): Promise<void> {
  search.value = ''
  statusFilter.value = 'all'
  await withScreenLoading(async () => {
    await refresh()
  }, 400)
}

const tenants = computed(() => response.value?.data ?? [])
const filteredTenants = computed(() => {
  const query = search.value.toLowerCase()

  return tenants.value.filter((tenant) => {
    const matchesSearch = query === ''
      || tenant.name.toLowerCase().includes(query)
      || tenant.code.toLowerCase().includes(query)
    const matchesStatus = statusFilter.value === 'all' || tenant.status === statusFilter.value

    return matchesSearch && matchesStatus
  })
})

const canCreate = computed(() => user.value !== null && (
  user.value.system_role === 'platform_admin'
  || user.value.tenants.some(tenant => tenant.role === 'owner' || tenant.role === 'admin')
))

function canUpdate(tenant: Tenant): boolean {
  return user.value?.system_role === 'platform_admin'
    || tenant.role === 'owner'
    || tenant.role === 'admin'
}

function canDelete(tenant: Tenant): boolean {
  return user.value?.system_role === 'platform_admin' || tenant.role === 'owner'
}

function openCreate(): void {
  editingTenant.value = null
  formErrors.value = {}
  formOpen.value = true
}

function openEdit(tenant: Tenant): void {
  editingTenant.value = tenant
  formErrors.value = {}
  formOpen.value = true
}

function openDelete(tenant: Tenant): void {
  deletingTenant.value = tenant
  deleteOpen.value = true
}

async function saveTenant(payload: TenantPayload): Promise<void> {
  formLoading.value = true

  try {
    if (editingTenant.value === null) {
      await api.request('/api/tenants', tenantResponseSchema, {
        body: payload,
        method: 'POST'
      })
    } else {
      await api.request(`/api/tenants/${editingTenant.value.id}`, tenantResponseSchema, {
        body: payload,
        method: 'PATCH'
      })
    }

    await Promise.all([refresh(), load(true)])
    formOpen.value = false
    formErrors.value = {}
    toast.add({
      title: editingTenant.value === null ? 'Tenant created' : 'Tenant updated',
      description: 'The tenant has been saved.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    formErrors.value = error.errors
    toast.add({
      title: 'Unable to save tenant',
      description: error.message,
      color: 'error'
    })
  } finally {
    formLoading.value = false
  }
}

async function deleteTenant(): Promise<void> {
  if (deletingTenant.value === null) {
    throw new Error('A tenant is required before deletion.')
  }

  deleteLoading.value = true

  try {
    await api.request(`/api/tenants/${deletingTenant.value.id}`, z.unknown(), { method: 'DELETE' })
    await Promise.all([refresh(), load(true)])
    deleteOpen.value = false
    toast.add({
      title: 'Tenant deleted',
      description: 'The tenant and its projects and assets have been deleted.',
      color: 'success'
    })
  } catch (error: unknown) {
    if (!(error instanceof ApiError)) {
      throw error
    }

    toast.add({
      title: 'Unable to delete tenant',
      description: error.message,
      color: 'error'
    })
  } finally {
    deleteLoading.value = false
  }
}

watch(filteredTenants, () => {
  pagination.value.pageIndex = 0
})

function getColumnKey(column: TableColumn<Tenant>): string {
  return column.id ?? 'column'
}

const columns: TableColumn<Tenant>[] = [
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
    id: 'role',
    accessorKey: 'role',
    header: 'Your role',
    cell: ({ row }) => {
      const role = row.original.role

      if (role === null) {
        return user.value?.system_role === 'platform_admin'
          ? h(UBadge, { color: 'warning', variant: 'subtle' }, () => 'Platform admin')
          : h('span', { class: 'text-muted' }, '—')
      }

      return h(
        UBadge,
        {
          class: 'capitalize',
          color: role === 'owner' ? 'primary' : 'neutral',
          variant: 'subtle'
        },
        () => role
      )
    }
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      canUpdate(row.original)
        ? h(UButton, {
            'icon': 'i-lucide-pencil',
            'color': 'neutral',
            'variant': 'ghost',
            'aria-label': `Edit ${row.original.name}`,
            'onClick': () => openEdit(row.original)
          })
        : null,
      canDelete(row.original)
        ? h(UButton, {
            'icon': 'i-lucide-trash-2',
            'color': 'error',
            'variant': 'ghost',
            'aria-label': `Delete ${row.original.name}`,
            'onClick': () => openDelete(row.original)
          })
        : null
    ])
  }
]

const displayColumns = computed(() => columns
  .filter(column => getColumnKey(column) !== 'actions')
  .map(column => ({
    label: typeof column.header === 'string' ? column.header : upperFirst(getColumnKey(column)),
    type: 'checkbox' as const,
    checked: columnVisibility.value?.[getColumnKey(column)] !== false,
    onUpdateChecked(checked: boolean) {
      table.value?.tableApi?.getColumn(getColumnKey(column))?.toggleVisibility(checked)
    },
    onSelect(event?: Event) {
      event?.preventDefault()
    }
  })))

const errorMessage = computed(() => {
  if (!(error.value instanceof ApiError)) {
    return 'The tenants could not be loaded.'
  }

  return error.value.message
})
</script>

<template>
  <UDashboardPanel id="tenants" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Tenants">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="canCreate"
            label="Add tenant"
            icon="i-lucide-plus"
            @click="openCreate"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4 sm:gap-6 lg:gap-8">
        <UAlert
          v-if="status === 'error'"
          color="error"
          variant="subtle"
          title="Unable to load tenants"
          :description="errorMessage"
        />

        <div class="flex flex-wrap items-center justify-between gap-1.5">
          <UInput
            v-model="search"
            class="w-64"
            icon="i-lucide-search"
            placeholder="Search tenants..."
          />

          <div class="flex flex-wrap items-center gap-1.5">
            <USelect
              v-model="statusFilter"
              :items="[
                { label: 'All', value: 'all' },
                { label: 'Active', value: 'active' },
                { label: 'Inactive', value: 'inactive' }
              ]"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
              placeholder="Filter status"
              class="min-w-28"
            />

            <UDropdownMenu
              :items="[displayColumns]"
              :content="{ align: 'end' }"
            >
              <UButton
                label="Display"
                color="neutral"
                variant="outline"
                trailing-icon="i-lucide-settings-2"
              />
            </UDropdownMenu>

            <ResetFiltersButton :loading="status === 'pending'" @click="resetFilters" />
          </div>
        </div>

        <UTable
          ref="table"
          v-model:column-visibility="columnVisibility"
          v-model:pagination="pagination"
          :pagination-options="{
            getPaginationRowModel: getPaginationRowModel()
          }"
          class="shrink-0"
          :data="filteredTenants"
          :columns="columns"
          :loading="status === 'pending'"
          :ui="{
            base: 'table-fixed border-separate border-spacing-0',
            thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
            td: 'border-b border-default',
            separator: 'h-0'
          }"
        />

        <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
          <div class="text-sm text-muted">
            {{ filteredTenants.length }} row(s).
          </div>

          <UPagination
            :default-page="pagination.pageIndex + 1"
            :items-per-page="pagination.pageSize"
            :total="filteredTenants.length"
            @update:page="(page: number) => table?.tableApi?.setPageIndex(page - 1)"
          />
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <TenantFormModal
    v-model:open="formOpen"
    :tenant="editingTenant"
    :loading="formLoading"
    :errors="formErrors"
    @submit="saveTenant"
  />

  <ConfirmDeleteModal
    v-model:open="deleteOpen"
    :title="`Delete ${deletingTenant?.name ?? 'tenant'}`"
    description="This action cannot be undone and will delete its projects and assets."
    :loading="deleteLoading"
    @confirm="deleteTenant"
  />
</template>
