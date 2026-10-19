import { useApi } from '~/composables/useApi'
import { overviewResponseSchema } from '~/types/overview'
import type { Overview } from '~/types/overview'

export function useOverview() {
  const api = useApi()

  async function loadProjectOverview(tenantId: string, projectId: string): Promise<Overview> {
    return (await api.request(`/api/tenants/${tenantId}/projects/${projectId}/overview`, overviewResponseSchema)).data
  }

  return { loadProjectOverview }
}
