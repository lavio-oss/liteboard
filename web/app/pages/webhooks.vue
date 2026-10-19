<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAlerting } from '~/composables/useAlerting'
import { useAlertingProject } from '~/composables/useAlertingProject'
import type { WebhookDelivery, WebhookEndpoint, WebhookEndpointInput } from '~/types/alerting'

definePageMeta({
  middleware: 'auth'
})

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const toast = useToast()
const alerting = useAlerting()
const {
  canAdminInSelectedProject,
  canWriteInSelectedProject,
  formProjectOptions,
  notifyError,
  projectSelectOptions,
  projects,
  projectsError,
  projectsStatus,
  requireProject,
  selectedProject,
  selectedProjectId,
  sortableHeader
} = await useAlertingProject()

// Endpoints are only readable by project admins and engineers, so other roles never request them.
const { data, status, error, refresh } = await useAsyncData(
  'liteboard-webhook-endpoints',
  async () => {
    const project = selectedProject.value

    if (project === null || !canWriteInSelectedProject.value) {
      return { endpoints: [] as WebhookEndpoint[] }
    }

    return { endpoints: await alerting.loadWebhookEndpoints(project.tenant_id, project.id) }
  },
  { watch: [selectedProjectId] }
)

const endpoints = computed(() => data.value?.endpoints ?? [])

// Webhooks
const endpointFormOpen = ref(false)
const endpointFormLoading = ref(false)
const editingEndpoint = ref<WebhookEndpoint | null>(null)
const secretOpen = ref(false)
const revealedSecret = ref('')
const rotateOpen = ref(false)
const rotateLoading = ref(false)
const rotatingEndpoint = ref<WebhookEndpoint | null>(null)
const deliveriesOpen = ref(false)
const deliveriesLoading = ref(false)
const deliveriesEndpoint = ref<WebhookEndpoint | null>(null)
const deliveries = ref<WebhookDelivery[]>([])
const redeliveringId = ref<string | null>(null)

function showSecret(secret: string): void {
  revealedSecret.value = secret
  secretOpen.value = true
}

watch(secretOpen, (isOpen) => {
  if (!isOpen) {
    revealedSecret.value = ''
  }
})

function openEndpointForm(endpointId: string | null): void {
  const endpoint = endpointId === null ? null : endpoints.value.find(item => item.id === endpointId)

  if (endpoint === undefined) {
    throw new Error('The selected endpoint is not available.')
  }

  editingEndpoint.value = endpoint
  endpointFormOpen.value = true
}

function openRotate(endpointId: string): void {
  const endpoint = endpoints.value.find(item => item.id === endpointId)

  if (endpoint === undefined) {
    throw new Error('The selected endpoint is not available.')
  }

  rotatingEndpoint.value = endpoint
  rotateOpen.value = true
}

async function saveEndpoint({ input }: { projectId: string, input: WebhookEndpointInput }): Promise<void> {
  const project = requireProject()
  const isCreate = editingEndpoint.value === null
  endpointFormLoading.value = true

  try {
    if (editingEndpoint.value === null) {
      showSecret((await alerting.createWebhookEndpoint(project.tenant_id, project.id, input)).secret)
    } else {
      await alerting.updateWebhookEndpoint(project.tenant_id, project.id, editingEndpoint.value.id, input)
    }

    await refresh()
    endpointFormOpen.value = false
    if (!isCreate) {
      toast.add({ title: 'Endpoint updated', description: 'The webhook endpoint has been saved.', color: 'success' })
    }
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to save webhook endpoint')
  } finally {
    endpointFormLoading.value = false
  }
}

async function toggleEndpoint(endpoint: WebhookEndpoint): Promise<void> {
  const project = requireProject()

  try {
    await alerting.updateWebhookEndpoint(project.tenant_id, project.id, endpoint.id, { enabled: !endpoint.enabled })
    await refresh()
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to update webhook endpoint')
  }
}

async function rotateSelectedSecret(): Promise<void> {
  const project = requireProject()

  if (rotatingEndpoint.value === null) {
    throw new Error('An endpoint is required before rotating its secret.')
  }

  rotateLoading.value = true

  try {
    const rotated = await alerting.rotateWebhookSecret(project.tenant_id, project.id, rotatingEndpoint.value.id)
    await refresh()
    rotateOpen.value = false
    showSecret(rotated.secret)
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to rotate secret')
  } finally {
    rotateLoading.value = false
  }
}

async function loadDeliveries(): Promise<void> {
  const project = requireProject()

  if (deliveriesEndpoint.value === null) {
    return
  }

  deliveriesLoading.value = true

  try {
    deliveries.value = await alerting.loadWebhookDeliveries(project.tenant_id, project.id, deliveriesEndpoint.value.id)
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to load deliveries')
  } finally {
    deliveriesLoading.value = false
  }
}

async function openDeliveries(endpointId: string): Promise<void> {
  const endpoint = endpoints.value.find(item => item.id === endpointId)

  if (endpoint === undefined) {
    throw new Error('The selected endpoint is not available.')
  }

  deliveriesEndpoint.value = endpoint
  deliveries.value = []
  deliveriesOpen.value = true
  await loadDeliveries()
}

async function redeliver(deliveryId: string): Promise<void> {
  const project = requireProject()

  if (deliveriesEndpoint.value === null) {
    throw new Error('An endpoint is required before redelivering.')
  }

  redeliveringId.value = deliveryId

  try {
    await alerting.redeliverWebhookDelivery(project.tenant_id, project.id, deliveriesEndpoint.value.id, deliveryId)
    toast.add({ title: 'Delivery queued', description: 'The dispatcher will resend it with the same event id.', color: 'success' })
    await loadDeliveries()
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to redeliver')
  } finally {
    redeliveringId.value = null
  }
}

const endpointColumns: TableColumn<WebhookEndpoint>[] = [
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
    id: 'url',
    accessorKey: 'url',
    header: 'URL',
    cell: ({ row }) => h('span', { class: 'font-mono text-xs break-all' }, row.original.url)
  },
  {
    id: 'events',
    header: 'Events',
    cell: ({ row }) => h('div', { class: 'flex flex-wrap gap-1' }, row.original.event_types.map(type => h(
      UBadge,
      { color: 'neutral', variant: 'subtle' },
      () => type
    )))
  },
  {
    id: 'enabled',
    accessorKey: 'enabled',
    header: sortableHeader('Status'),
    cell: ({ row }) => h(
      UBadge,
      { class: 'capitalize', color: row.original.enabled ? 'success' : 'neutral', variant: 'subtle' },
      () => row.original.enabled ? 'enabled' : 'disabled'
    )
  },
  {
    id: 'secret_rotated_at',
    accessorKey: 'secret_rotated_at',
    header: sortableHeader('Secret rotated'),
    cell: ({ row }) => formatDateTime(row.original.secret_rotated_at)
  },
  {
    id: 'created_at',
    accessorKey: 'created_at',
    header: sortableHeader('Created'),
    cell: ({ row }) => formatDateTime(row.original.created_at)
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const buttons = [
        h(UButton, {
          'icon': 'i-lucide-history',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `Deliveries of ${row.original.name}`,
          'onClick': () => openDeliveries(row.original.id)
        })
      ]

      if (canAdminInSelectedProject.value) {
        buttons.push(
          h(UButton, {
            'icon': row.original.enabled ? 'i-lucide-pause' : 'i-lucide-play',
            'color': 'neutral',
            'variant': 'ghost',
            'aria-label': `${row.original.enabled ? 'Disable' : 'Enable'} ${row.original.name}`,
            'onClick': () => toggleEndpoint(row.original)
          }),
          h(UButton, {
            'icon': 'i-lucide-pencil',
            'color': 'neutral',
            'variant': 'ghost',
            'aria-label': `Edit ${row.original.name}`,
            'onClick': () => openEndpointForm(row.original.id)
          }),
          h(UButton, {
            'icon': 'i-lucide-key-round',
            'color': 'warning',
            'variant': 'ghost',
            'aria-label': `Rotate secret of ${row.original.name}`,
            'onClick': () => openRotate(row.original.id)
          })
        )
      }

      return h('div', { class: 'flex justify-end gap-1' }, buttons)
    }
  }
]

const errorMessage = computed(() => {
  if (projectsError.value instanceof ApiError) {
    return projectsError.value.message
  }

  if (error.value instanceof ApiError) {
    return error.value.message
  }

  return 'The webhook endpoints could not be loaded.'
})
</script>

<template>
  <UDashboardPanel id="liteboard-webhooks" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Rules & schemas">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="canAdminInSelectedProject"
            label="Add endpoint"
            icon="i-lucide-plus"
            @click="openEndpointForm(null)"
          />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <ConfigurationMenu />
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="projectsStatus === 'error' || status === 'error'"
          color="error"
          variant="subtle"
          title="Unable to load webhook endpoints"
          :description="errorMessage"
        />

        <UAlert
          v-if="projectsStatus !== 'pending' && projects.length === 0"
          color="warning"
          variant="subtle"
          title="No project assigned"
          description="Your account is not currently assigned to any project. Contact an administrator to request access."
        />

        <UAlert
          v-else-if="projectsStatus !== 'pending' && !canWriteInSelectedProject"
          color="info"
          variant="subtle"
          title="Webhooks are managed by project admins and engineers"
          description="Ask a project admin for access to configure where alerts are delivered."
        />

        <AdminTable
          v-else
          :columns="endpointColumns"
          :data="endpoints"
          :loading="projectsStatus === 'pending' || status === 'pending'"
          :show-status-filter="false"
          search-placeholder="Search endpoints..."
        >
          <template #search-trailing>
            <USelect
              v-if="projects.length > 1"
              v-model="selectedProjectId"
              :items="projectSelectOptions"
              class="min-w-44"
              :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            />
          </template>
        </AdminTable>
      </div>

      <AlertingWebhookEndpointFormModal
        v-if="selectedProject !== null"
        v-model:open="endpointFormOpen"
        :endpoint="editingEndpoint"
        :project-options="formProjectOptions"
        :loading="endpointFormLoading"
        @submit="saveEndpoint"
      />

      <UModal
        v-model:open="rotateOpen"
        :title="`Rotate secret of ${rotatingEndpoint?.name ?? 'endpoint'}`"
        description="The old secret stops working immediately. Update the receiver before the next delivery."
      >
        <template #body>
          <div class="flex justify-end gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="subtle"
              :disabled="rotateLoading"
              @click="rotateOpen = false"
            />
            <UButton
              label="Rotate"
              color="warning"
              :loading="rotateLoading"
              @click="rotateSelectedSecret"
            />
          </div>
        </template>
      </UModal>

      <AlertingWebhookSecretModal
        v-model:open="secretOpen"
        :secret="revealedSecret"
      />

      <AlertingWebhookDeliveriesModal
        v-model:open="deliveriesOpen"
        :endpoint="deliveriesEndpoint"
        :deliveries="deliveries"
        :can-redeliver="canAdminInSelectedProject"
        :loading="deliveriesLoading"
        :redelivering-id="redeliveringId"
        @redeliver="redeliver"
        @refresh="loadDeliveries"
      />
    </template>
  </UDashboardPanel>
</template>
