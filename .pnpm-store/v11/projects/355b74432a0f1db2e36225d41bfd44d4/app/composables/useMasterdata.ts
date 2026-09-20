import { z } from 'zod'
import { useApi } from '~/composables/useApi'
import {
  factoryResponseSchema,
  factoriesResponseSchema,
  productionLineResponseSchema,
  productionLinesResponseSchema,
  projectResponseSchema,
  projectsResponseSchema
} from '~/types/masterdata'
import type {
  Factory,
  FactoryInput,
  ProductionLine,
  ProductionLineInput,
  Project,
  ProjectInput,
  TenantHierarchy
} from '~/types/masterdata'

export function useMasterdata() {
  const api = useApi()

  async function loadProjects(tenantId: number): Promise<Project[]> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects?per_page=100`,
      projectsResponseSchema
    )

    return response.data
  }

  async function loadFactories(tenantId: number): Promise<Factory[]> {
    const projects = await loadProjects(tenantId)
    const responses = await Promise.all(projects.map(project => api.request(
      `/api/tenants/${tenantId}/projects/${project.id}/factories?per_page=100`,
      factoriesResponseSchema
    )))

    return responses.flatMap(response => response.data)
  }

  async function loadProductionLines(tenantId: number): Promise<ProductionLine[]> {
    const factories = await loadFactories(tenantId)
    const responses = await Promise.all(factories.map(factory => api.request(
      `/api/tenants/${tenantId}/projects/${factory.project_id}/factories/${factory.id}/production-lines?per_page=100`,
      productionLinesResponseSchema
    )))

    return responses.flatMap(response => response.data)
  }

  async function loadTenantHierarchy(tenantId: number): Promise<TenantHierarchy> {
    const projects = await loadProjects(tenantId)
    const factoryResponses = await Promise.all(projects.map(project => api.request(
      `/api/tenants/${tenantId}/projects/${project.id}/factories?per_page=100`,
      factoriesResponseSchema
    )))
    const factories = factoryResponses.flatMap(response => response.data)
    const productionLineResponses = await Promise.all(factories.map(factory => api.request(
      `/api/tenants/${tenantId}/projects/${factory.project_id}/factories/${factory.id}/production-lines?per_page=100`,
      productionLinesResponseSchema
    )))

    return {
      factories,
      productionLines: productionLineResponses.flatMap(response => response.data),
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

  async function createFactory(tenantId: number, projectId: number, payload: FactoryInput): Promise<Factory> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/factories`,
      factoryResponseSchema,
      { body: payload, method: 'POST' }
    )

    return response.data
  }

  async function updateFactory(
    tenantId: number,
    projectId: number,
    factoryId: number,
    payload: Partial<FactoryInput>
  ): Promise<Factory> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/factories/${factoryId}`,
      factoryResponseSchema,
      { body: payload, method: 'PATCH' }
    )

    return response.data
  }

  async function deleteFactory(tenantId: number, projectId: number, factoryId: number): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/factories/${factoryId}`,
      z.unknown(),
      { method: 'DELETE' }
    )
  }

  async function createProductionLine(
    tenantId: number,
    projectId: number,
    factoryId: number,
    payload: ProductionLineInput
  ): Promise<ProductionLine> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/factories/${factoryId}/production-lines`,
      productionLineResponseSchema,
      { body: payload, method: 'POST' }
    )

    return response.data
  }

  async function updateProductionLine(
    tenantId: number,
    projectId: number,
    factoryId: number,
    productionLineId: number,
    payload: Partial<ProductionLineInput>
  ): Promise<ProductionLine> {
    const response = await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/factories/${factoryId}/production-lines/${productionLineId}`,
      productionLineResponseSchema,
      { body: payload, method: 'PATCH' }
    )

    return response.data
  }

  async function deleteProductionLine(
    tenantId: number,
    projectId: number,
    factoryId: number,
    productionLineId: number
  ): Promise<void> {
    await api.request(
      `/api/tenants/${tenantId}/projects/${projectId}/factories/${factoryId}/production-lines/${productionLineId}`,
      z.unknown(),
      { method: 'DELETE' }
    )
  }

  return {
    createFactory,
    createProductionLine,
    createProject,
    deleteFactory,
    deleteProductionLine,
    deleteProject,
    loadFactories,
    loadProductionLines,
    loadProjects,
    loadTenantHierarchy,
    updateFactory,
    updateProductionLine,
    updateProject
  }
}
