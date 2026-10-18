<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useAlerting } from '~/composables/useAlerting'
import { useAlertingProject } from '~/composables/useAlertingProject'
import type { DeviceProfile } from '~/types/admin'
import type { AlertRule, AlertRuleInput } from '~/types/alerting'
import { describeAlertRule } from '~/utils/alertRuleDefinition'

definePageMeta({
  middleware: 'auth'
})

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const SeverityBadge = resolveComponent('AlertingSeverityBadge')
const toast = useToast()
const { loadDeviceProfiles } = useAdmin()
const alerting = useAlerting()
const {
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

const { data, status, error, refresh } = await useAsyncData(
  'liteboard-alert-rules',
  async () => {
    const project = selectedProject.value

    if (project === null) {
      return { profiles: [] as DeviceProfile[], rules: [] as AlertRule[] }
    }

    const [rules, profiles] = await Promise.all([
      alerting.loadAlertRules(project.tenant_id, project.id),
      loadDeviceProfiles(project.tenant_id, project.id)
    ])

    return { profiles, rules }
  },
  { watch: [selectedProjectId] }
)

const rules = computed(() => data.value?.rules ?? [])
const profiles = computed(() => data.value?.profiles ?? [])

// Rules
const ruleFormOpen = ref(false)
const ruleFormLoading = ref(false)
const editingRule = ref<AlertRule | null>(null)
const archiveOpen = ref(false)
const archiveLoading = ref(false)
const archivingRule = ref<AlertRule | null>(null)

function openRuleCreate(): void {
  editingRule.value = null
  ruleFormOpen.value = true
}

function openRuleRevision(ruleId: string): void {
  const rule = rules.value.find(item => item.id === ruleId)

  if (rule === undefined) {
    throw new Error('The selected rule is not available.')
  }

  editingRule.value = rule
  ruleFormOpen.value = true
}

function openArchive(ruleId: string): void {
  const rule = rules.value.find(item => item.id === ruleId)

  if (rule === undefined) {
    throw new Error('The selected rule is not available.')
  }

  archivingRule.value = rule
  archiveOpen.value = true
}

async function saveRule({ input }: { projectId: string, input: AlertRuleInput }): Promise<void> {
  const project = requireProject()
  const isCreate = editingRule.value === null
  ruleFormLoading.value = true

  try {
    if (editingRule.value === null) {
      await alerting.createAlertRule(project.tenant_id, project.id, input)
    } else {
      await alerting.createAlertRuleRevision(project.tenant_id, project.id, editingRule.value.id, input.definition)
    }

    await refresh()
    ruleFormOpen.value = false
    toast.add({
      title: isCreate ? 'Rule created' : 'Revision created',
      description: 'The rule applies to telemetry received from now on.',
      color: 'success'
    })
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to save rule')
  } finally {
    ruleFormLoading.value = false
  }
}

async function archiveSelectedRule(): Promise<void> {
  const project = requireProject()

  if (archivingRule.value === null) {
    throw new Error('A rule is required before archiving.')
  }

  archiveLoading.value = true

  try {
    await alerting.archiveAlertRule(project.tenant_id, project.id, archivingRule.value.id)
    await refresh()
    archiveOpen.value = false
    toast.add({ title: 'Rule archived', description: 'Its active alerts were cleared.', color: 'success' })
  } catch (cause: unknown) {
    notifyError(cause, 'Unable to archive rule')
  } finally {
    archiveLoading.value = false
  }
}

const ruleColumns: TableColumn<AlertRule>[] = [
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
    id: 'condition',
    header: 'Condition',
    cell: ({ row }) => {
      const definition = row.original.current_revision?.definition

      return definition === undefined ? '—' : describeAlertRule(definition)
    }
  },
  {
    id: 'severity',
    header: 'Severity',
    cell: ({ row }) => {
      const severity = row.original.current_revision?.definition.severity

      return severity === undefined ? '—' : h(SeverityBadge, { severity })
    }
  },
  {
    id: 'profile',
    header: 'Profile',
    cell: ({ row }) => profiles.value.find(profile => profile.id === row.original.device_profile_id)?.name ?? '—'
  },
  {
    id: 'revision',
    header: 'Revision',
    cell: ({ row }) => `v${row.original.current_revision?.revision ?? '—'}`
  },
  {
    id: 'status',
    accessorKey: 'status',
    header: sortableHeader('Status'),
    cell: ({ row }) => h(
      UBadge,
      { class: 'capitalize', color: row.original.status === 'active' ? 'success' : 'neutral', variant: 'subtle' },
      () => row.original.status
    )
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
      if (row.original.status !== 'active' || !canWriteInSelectedProject.value) {
        return null
      }

      return h('div', { class: 'flex justify-end gap-1' }, [
        h(UButton, {
          'icon': 'i-lucide-git-branch-plus',
          'color': 'neutral',
          'variant': 'ghost',
          'aria-label': `New revision of ${row.original.name}`,
          'onClick': () => openRuleRevision(row.original.id)
        }),
        h(UButton, {
          'icon': 'i-lucide-archive',
          'color': 'error',
          'variant': 'ghost',
          'aria-label': `Archive ${row.original.name}`,
          'onClick': () => openArchive(row.original.id)
        })
      ])
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

  return 'The alert rules could not be loaded.'
})
</script>

<template>
  <UDashboardPanel id="liteboard-alert-rules" :ui="{ body: 'lg:pt-4 lg:pb-12' }">
    <template #header>
      <UDashboardNavbar title="Rules & schemas">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="canWriteInSelectedProject"
            label="Add rule"
            icon="i-lucide-plus"
            @click="openRuleCreate"
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
          title="Unable to load alert rules"
          :description="errorMessage"
        />

        <UAlert
          v-if="projectsStatus !== 'pending' && projects.length === 0"
          color="warning"
          variant="subtle"
          title="No project assigned"
          description="Your account is not currently assigned to any project. Contact an administrator to request access."
        />

        <AdminTable
          v-else
          :columns="ruleColumns"
          :data="rules"
          :loading="projectsStatus === 'pending' || status === 'pending'"
          :show-status-filter="false"
          search-placeholder="Search rules..."
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

      <AlertingAlertRuleFormModal
        v-if="selectedProject !== null"
        v-model:open="ruleFormOpen"
        :rule="editingRule"
        :project-options="formProjectOptions"
        :profiles="profiles"
        :loading="ruleFormLoading"
        @submit="saveRule"
      />

      <ConfirmDeleteModal
        v-model:open="archiveOpen"
        :title="`Archive ${archivingRule?.name ?? 'rule'}`"
        description="The rule stops evaluating and its active alerts are cleared as rule_archived. This cannot be undone."
        :loading="archiveLoading"
        @confirm="archiveSelectedRule"
      />
    </template>
  </UDashboardPanel>
</template>
