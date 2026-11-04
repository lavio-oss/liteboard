import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useSortableHeader } from '~/composables/useSortableHeader'
import { useTenantContext } from '~/composables/useTenantContext'

/**
 * Project picker and permissions shared by the Alerts, Alert rules and Webhooks screens:
 * the projects the user participates in across tenants, one selected project, and what they may do in it.
 */
export async function useAlertingProject() {
  const toast = useToast()
  const { loadTenants, tenants } = useTenantContext()
  const { loadProjects } = useAdmin()
  const nuxtApp = useNuxtApp()
  await loadTenants()

  const tenantIds = computed(() => tenants.value.map(tenant => tenant.id))

  // The Nuxt context is lost after the await above, so re-enter it before calling a Nuxt composable.
  const { data: projectsData, status: projectsStatus, error: projectsError } = await nuxtApp.runWithContext(() => useAsyncData(
    'liteboard-alerting-projects',
    () => Promise.all(tenants.value.map(tenant => loadProjects(tenant.id, { participating: true })))
      .then(lists => lists.flat()),
    { watch: [tenantIds] }
  ))

  const projects = computed(() => projectsData.value ?? [])
  const selectedProjectId = ref<string | undefined>(undefined)

  watch(projects, (nextProjects) => {
    if (selectedProjectId.value === undefined || !nextProjects.some(project => project.id === selectedProjectId.value)) {
      selectedProjectId.value = nextProjects[0]?.id
    }
  }, { immediate: true })

  const selectedProject = computed(() => projects.value.find(project => project.id === selectedProjectId.value) ?? null)
  const projectSelectOptions = computed(() => projects.value.map(project => ({
    label: project.name,
    value: project.id
  })))
  const formProjectOptions = computed(() => selectedProject.value === null
    ? []
    : [{ label: selectedProject.value.name, value: selectedProject.value.id }])

  const selectedPermissions = computed(() => selectedProject.value?.permissions ?? [])
  const canWriteInSelectedProject = computed(() => selectedPermissions.value.includes('alert_rule.write'))
  const canAcknowledgeInSelectedProject = computed(() => selectedPermissions.value.includes('alert.acknowledge'))
  const canViewWebhooksInSelectedProject = computed(() => selectedPermissions.value.includes('webhook.view'))
  // Webhook changes, secrets and redelivery.
  const canAdminInSelectedProject = computed(() => selectedPermissions.value.includes('webhook.manage'))

  function requireProject() {
    if (selectedProject.value === null) {
      throw new Error('A project is required.')
    }

    return selectedProject.value
  }

  function notifyError(cause: unknown, title: string): void {
    if (!(cause instanceof ApiError)) {
      throw cause
    }

    toast.add({ title, description: Object.values(cause.errors).flat()[0] ?? cause.message, color: 'error' })
  }

  const sortableHeader = useSortableHeader()

  return {
    canAcknowledgeInSelectedProject,
    canAdminInSelectedProject,
    canViewWebhooksInSelectedProject,
    canWriteInSelectedProject,
    formProjectOptions,
    notifyError,
    projects,
    projectsError,
    projectsStatus,
    projectSelectOptions,
    requireProject,
    selectedProject,
    selectedProjectId,
    sortableHeader
  }
}
