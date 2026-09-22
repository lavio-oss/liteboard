import { z } from 'zod'
import { useApi } from '~/composables/useApi'
import {
  assetResponseSchema,
  assetsResponseSchema,
  availableProjectUsersResponseSchema,
  projectResponseSchema,
  projectsResponseSchema,
  projectUserResponseSchema,
  projectUsersResponseSchema
} from '~/types/masterdata'
import { tenantUserResponseSchema, tenantUsersResponseSchema } from '~/types/auth'
import type {
  Asset,
  AssetInput,
  AvailableProjectUser,
  Project,
  ProjectInput,
  ProjectUser,
  ProjectUserAccountInput,
  ProjectUserInput,
  TenantHierarchy
} from '~/types/masterdata'
import type { TenantUser, TenantUserCreateInput, TenantUserUpdateInput } from '~/types/auth'

export function useMasterdata() {
  const api = useApi()

  async function loadProjects(tenantId: number): Promise<Project[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects?per_page=100`,
      projectsResponseSchema
    )

    return response.data
  }

  async function loadAssets(tenantId: number, projectId: number): Promise<Asset[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/assets?per_page=100`,
      assetsResponseSchema
    )

    return response.data
  }

  async function loadTenantHierarchy(tenantId: number): Promise<TenantHierarchy> {
    const projects = await loadProjects(tenantId)
    const assetResponses = await Promise.all(
      projects.map(project => loadAssets(tenantId, project.id))
    )

    return {
      assets: assetResponses.flatMap(response => response),
      projects
    }
  }

  async function createProject(tenantId: number, payload: ProjectInput): Promise<Project> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects`,
      projectResponseSchema,
      { body: payload, method: 'POST' }
    )

    return response.data
  }

  async function updateProject(tenantId: number, projectId: number, payload: Partial<ProjectInput>): Promise<Project> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}`,
      projectResponseSchema,
      { body: payload, method: 'PATCH' }
    )

    return response.data
  }

  async function deleteProject(tenantId: number, projectId: number): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}`,
      z.unknown(),
      { method: 'DELETE' }
    )
  }

  async function createAsset(tenantId: number, projectId: number, payload: AssetInput): Promise<Asset> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/assets`,
      assetResponseSchema,
      { body: payload, method: 'POST' }
    )

    return response.data
  }

  async function updateAsset(
    tenantId: number,
    projectId: number,
    assetId: number,
    payload: Partial<AssetInput>
  ): Promise<Asset> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/assets/${assetId}`,
      assetResponseSchema,
      { body: payload, method: 'PATCH' }
    )

    return response.data
  }

  async function deleteAsset(tenantId: number, projectId: number, assetId: number): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/assets/${assetId}`,
      z.unknown(),
      { method: 'DELETE' }
    )
  }

  async function loadProjectUsers(tenantId: number, projectId: number): Promise<ProjectUser[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/users?per_page=100`,
      projectUsersResponseSchema
    )

    return response.data
  }

  async function loadTenantUsers(tenantId: number): Promise<TenantUser[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/users?per_page=100`,
      tenantUsersResponseSchema
    )

    return response.data
  }

  async function createTenantUser(tenantId: number, payload: TenantUserCreateInput): Promise<TenantUser> {
    const response = await api.request(
      `/api/tenants/${tenantId}/users`,
      tenantUserResponseSchema,
      { body: payload, method: 'POST' }
    )

    return response.data
  }

  async function loadAvailableProjectUsers(tenantId: number, projectId: number): Promise<AvailableProjectUser[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/available-users?per_page=100`,
      availableProjectUsersResponseSchema
    )

    return response.data
  }

  async function attachProjectUser(
    tenantId: number,
    projectId: number,
    payload: ProjectUserInput
  ): Promise<ProjectUser> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/users`,
      projectUserResponseSchema,
      { body: payload, method: 'POST' }
    )

    return response.data
  }

  async function createProjectUserAccount(
    tenantId: number,
    projectId: number,
    payload: ProjectUserAccountInput
  ): Promise<ProjectUser> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/user-accounts`,
      projectUserResponseSchema,
      { body: payload, method: 'POST' }
    )

    return response.data
  }

  async function updateProjectUser(
    tenantId: number,
    projectId: number,
    userId: number,
    role: ProjectUserInput['role']
  ): Promise<ProjectUser> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/users/${userId}`,
      projectUserResponseSchema,
      { body: { role }, method: 'PATCH' }
    )

    return response.data
  }

  async function removeProjectUser(tenantId: number, projectId: number, userId: number): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/users/${userId}`,
      z.unknown(),
      { method: 'DELETE' }
    )
  }

  async function updateTenantUser(
    tenantId: number,
    userId: number,
    payload: TenantUserUpdateInput
  ): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/users/${userId}`,
      tenantUserResponseSchema,
      { body: payload, method: 'PATCH' }
    )
  }

  async function deleteTenantUser(tenantId: number, userId: number): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/users/${userId}`,
      z.unknown(),
      { method: 'DELETE' }
    )
  }

  return {
    attachProjectUser,
    createAsset,
    createProject,
    createProjectUserAccount,
    createTenantUser,
    deleteAsset,
    deleteProject,
    deleteTenantUser,
    loadAssets,
    loadAvailableProjectUsers,
    loadProjectUsers,
    loadProjects,
    loadTenantHierarchy,
    loadTenantUsers,
    removeProjectUser,
    updateAsset,
    updateProject,
    updateProjectUser,
    updateTenantUser
  }
}
