import { computed } from 'vue'
import { useApi } from '~/composables/useApi'
import { tenantCollectionResponseSchema } from '~/types/auth'
import type { Tenant } from '~/types/auth'

export function useTenantContext() {
  const api = useApi()
  const { user } = useAuth()
  const tenants = useState<Tenant[]>('tenant-context:tenants', () => [])
  const activeTenantId = useState<string | null>('tenant-context:active-id', () => null)
  const loaded = useState<boolean>('tenant-context:loaded', () => false)
  const loadedForUserId = useState<string | null>('tenant-context:user-id', () => null)
  const activeTenant = computed(() => tenants.value.find(tenant => tenant.id === activeTenantId.value) ?? null)
  const isPlatformAdmin = computed(() => user.value?.system_role === 'platform_admin')
  const canManageActiveTenant = computed(() => isPlatformAdmin.value
    || activeTenant.value?.role === 'owner'
    || activeTenant.value?.role === 'admin')

  async function loadTenants(force = false): Promise<Tenant[]> {
    const userId = user.value?.id ?? null

    if (loaded.value && loadedForUserId.value === userId && !force) {
      return tenants.value
    }

    const response = await api.request('/api/tenants', tenantCollectionResponseSchema)
    tenants.value = response.data
    loaded.value = true
    loadedForUserId.value = userId

    if (activeTenantId.value === null || !tenants.value.some(tenant => tenant.id === activeTenantId.value)) {
      activeTenantId.value = tenants.value[0]?.id ?? null
    }

    return tenants.value
  }

  function selectTenant(tenantId: string): void {
    if (!tenants.value.some(tenant => tenant.id === tenantId)) {
      throw new Error('The selected tenant is not available.')
    }

    activeTenantId.value = tenantId
  }

  return {
    activeTenant,
    activeTenantId,
    canManageActiveTenant,
    isPlatformAdmin,
    loadTenants,
    selectTenant,
    tenants
  }
}
