import { z } from 'zod'
import { useApi } from '~/composables/useApi'
import {
  assetResponseSchema,
  assetsResponseSchema,
  availableProjectUsersResponseSchema,
  deviceCredentialResponseSchema,
  deviceResponseSchema,
  devicesResponseSchema,
  projectResponseSchema,
  projectsResponseSchema,
  projectUserResponseSchema,
  projectUsersResponseSchema
} from '~/types/admin'
import { tenantUserResponseSchema, tenantUsersResponseSchema } from '~/types/auth'
import type {
  Asset,
  AssetInput,
  AvailableProjectUser,
  Device,
  DeviceCredential,
  DeviceCredentialInput,
  DeviceInput,
  Project,
  ProjectInput,
  ProjectUser,
  ProjectUserAccountInput,
  ProjectUserInput,
  TenantHierarchy
} from '~/types/admin'
import type { TenantUser, TenantUserCreateInput, TenantUserUpdateInput } from '~/types/auth'

export function useAdmin() {
  const api = useApi()

  async function loadProjects(tenantId: string): Promise<Project[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects?per_page=100`,
      projectsResponseSchema
    )

    return response.data
  }

  async function loadAssets(tenantId: string, projectId: string): Promise<Asset[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/assets?per_page=100`,
      assetsResponseSchema
    )

    return response.data
  }

  async function loadTenantDevices(tenantId: string): Promise<Device[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/devices?per_page=100`,
      devicesResponseSchema
    )

    return response.data
  }

  async function loadDevices(tenantId: string, projectId: string): Promise<Device[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/devices?per_page=100`,
      devicesResponseSchema
    )

    return response.data
  }

  async function loadTenantHierarchy(tenantId: string): Promise<TenantHierarchy> {
    const projects = await loadProjects(tenantId)
    const assetResponses = await Promise.all(
      projects.map(project => loadAssets(tenantId, project.id))
    )

    return {
      assets: assetResponses.flatMap(response => response),
      projects
    }
  }

  async function createProject(tenantId: string, payload: ProjectInput): Promise<Project> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects`,
      projectResponseSchema,
      { body: payload, method: 'POST' }
    )

    return response.data
  }

  async function updateProject(tenantId: string, projectId: string, payload: Partial<ProjectInput>): Promise<Project> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}`,
      projectResponseSchema,
      { body: payload, method: 'PATCH' }
    )

    return response.data
  }

  async function deleteProject(tenantId: string, projectId: string): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}`,
      z.unknown(),
      { method: 'DELETE' }
    )
  }

  async function createAsset(tenantId: string, projectId: string, payload: AssetInput): Promise<Asset> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/assets`,
      assetResponseSchema,
      { body: payload, method: 'POST' }
    )

    return response.data
  }

  async function updateAsset(
    tenantId: string,
    projectId: string,
    assetId: string,
    payload: Partial<AssetInput>
  ): Promise<Asset> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/assets/${assetId}`,
      assetResponseSchema,
      { body: payload, method: 'PATCH' }
    )

    return response.data
  }

  async function deleteAsset(tenantId: string, projectId: string, assetId: string): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/assets/${assetId}`,
      z.unknown(),
      { method: 'DELETE' }
    )
  }

  async function createDevice(tenantId: string, projectId: string, payload: DeviceInput): Promise<Device> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/devices`,
      deviceResponseSchema,
      { body: payload, method: 'POST' }
    )

    return response.data
  }

  async function updateDevice(
    tenantId: string,
    projectId: string,
    deviceId: string,
    payload: Partial<DeviceInput>
  ): Promise<Device> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/devices/${deviceId}`,
      deviceResponseSchema,
      { body: payload, method: 'PATCH' }
    )

    return response.data
  }

  async function deleteDevice(tenantId: string, projectId: string, deviceId: string): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/devices/${deviceId}`,
      z.unknown(),
      { method: 'DELETE' }
    )
  }

  async function loadDeviceCredentials(
    tenantId: string,
    projectId: string,
    deviceId: string
  ): Promise<DeviceCredential> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/devices/${deviceId}/credentials`,
      deviceCredentialResponseSchema
    )

    return response.data
  }

  async function updateDeviceCredentials(
    tenantId: string,
    projectId: string,
    deviceId: string,
    payload: DeviceCredentialInput
  ): Promise<DeviceCredential> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/devices/${deviceId}/credentials`,
      deviceCredentialResponseSchema,
      { body: payload, method: 'PUT' }
    )

    return response.data
  }

  async function rotateDeviceCredentials(
    tenantId: string,
    projectId: string,
    deviceId: string
  ): Promise<DeviceCredential> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/devices/${deviceId}/credentials/rotate`,
      deviceCredentialResponseSchema,
      { method: 'POST' }
    )

    return response.data
  }

  async function loadProjectUsers(tenantId: string, projectId: string): Promise<ProjectUser[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/users?per_page=100`,
      projectUsersResponseSchema
    )

    return response.data
  }

  async function loadTenantUsers(tenantId: string): Promise<TenantUser[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/users?per_page=100`,
      tenantUsersResponseSchema
    )

    return response.data
  }

  async function createTenantUser(tenantId: string, payload: TenantUserCreateInput): Promise<TenantUser> {
    const response = await api.request(
      `/api/tenants/${tenantId}/users`,
      tenantUserResponseSchema,
      { body: payload, method: 'POST' }
    )

    return response.data
  }

  async function loadAvailableProjectUsers(tenantId: string, projectId: string): Promise<AvailableProjectUser[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/available-users?per_page=100`,
      availableProjectUsersResponseSchema
    )

    return response.data
  }

  async function attachProjectUser(
    tenantId: string,
    projectId: string,
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
    tenantId: string,
    projectId: string,
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
    tenantId: string,
    projectId: string,
    userId: string,
    role: ProjectUserInput['role']
  ): Promise<ProjectUser> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/users/${userId}`,
      projectUserResponseSchema,
      { body: { role }, method: 'PATCH' }
    )

    return response.data
  }

  async function removeProjectUser(tenantId: string, projectId: string, userId: string): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/users/${userId}`,
      z.unknown(),
      { method: 'DELETE' }
    )
  }

  async function updateTenantUser(
    tenantId: string,
    userId: string,
    payload: TenantUserUpdateInput
  ): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/users/${userId}`,
      tenantUserResponseSchema,
      { body: payload, method: 'PATCH' }
    )
  }

  async function deleteTenantUser(tenantId: string, userId: string): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/users/${userId}`,
      z.unknown(),
      { method: 'DELETE' }
    )
  }

  return {
    attachProjectUser,
    createAsset,
    createDevice,
    createProject,
    createProjectUserAccount,
    createTenantUser,
    deleteAsset,
    deleteDevice,
    deleteProject,
    deleteTenantUser,
    loadAssets,
    loadAvailableProjectUsers,
    loadDeviceCredentials,
    loadDevices,
    loadProjectUsers,
    loadProjects,
    loadTenantDevices,
    loadTenantHierarchy,
    loadTenantUsers,
    removeProjectUser,
    rotateDeviceCredentials,
    updateAsset,
    updateDevice,
    updateDeviceCredentials,
    updateProject,
    updateProjectUser,
    updateTenantUser
  }
}
