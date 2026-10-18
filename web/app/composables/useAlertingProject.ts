import { ApiError } from '~/composables/useApi'
import { useAdmin } from '~/composables/useAdmin'
import { useSortableHeader } from '~/composables/useSortableHeader'
import { useTenantContext } from '~/composables/useTenantContext'
import { canDeleteProjectData, canWriteProjectData } from '~/utils/projectPermissions'

/**
 * Project picker and permissions shared by the Alerts, Alert rules and Webhooks screens:
 * the projects the user participates in across tenants, one selected project, and what they may do in it.
 */
export async function useAlertingProject() {
  const toast = useToast()
  const { isPlatformAdmin, loadTenants, tenants } = useTenantContext()
  const { loadProjects } = useAdmin()
  await loadTenants()

  const tenantIds = computed(() => tenants.value.map(tenant => tenant.id))
  const manageableTenantIds = computed(() => new Set(
    tenants.value
      .filter(tenant => isPlatformAdmin.value || tenant.role === 'owner' || tenant.role === 'admin')
      .map(tenant => tenant.id)
  ))

  const { data: projectsData, status: projectsStatus, error: projectsError } = await useAsyncData(
    'liteboard-alerting-projects',
    () => Promise.all(tenants.value.map(tenant => loadProjects(tenant.id, { participating: true })))
      .then(lists => lists.flat()),
    { watch: [tenantIds] }
  )

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

  const canManageTenant = computed(() => selectedProject.value !== null
    && (isPlatformAdmin.value || manageableTenantIds.value.has(selectedProject.value.tenant_id)))
  // Rules and acknowledgement need Admin/Engineer; webhook changes, secrets and redelivery need Admin.
  const canWriteInSelectedProject = computed(() => selectedProject.value !== null
    && canWriteProjectData(selectedProject.value.role, canManageTenant.value))
  const canAdminInSelectedProject = computed(() => selectedProject.value !== null
    && canDeleteProjectData(selectedProject.value.role, canManageTenant.value))

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
    canAdminInSelectedProject,
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
