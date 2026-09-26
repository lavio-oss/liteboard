import { z } from 'zod'
import { tenantRoleSchema } from '~/types/auth'

export const resourceStatusSchema = z.enum(['active', 'inactive'])
export const assetTypeSchema = z.enum(['site', 'factory', 'area', 'production_line', 'station', 'equipment'])

const resourceBaseSchema = z.object({
  id: z.string().uuid(),
  name: z.string(),
  status: resourceStatusSchema,
  created_at: z.string(),
  updated_at: z.string()
})

export const projectSchema = resourceBaseSchema.extend({
  tenant_id: z.string().uuid(),
  description: z.string().nullable()
})

export const assetSchema = resourceBaseSchema.extend({
  project_id: z.string().uuid(),
  parent_asset_id: z.string().uuid().nullable(),
  asset_type: assetTypeSchema,
  code: z.string(),
  location: z.string().nullable()
})

export const projectRoleSchema = z.enum(['admin', 'engineer', 'operator', 'viewer'])

export const projectUserSchema = z.object({
  id: z.string().uuid(),
  name: z.string(),
  email: z.string().email(),
  role: projectRoleSchema
})

export const availableProjectUserSchema = z.object({
  id: z.string().uuid(),
  name: z.string(),
  email: z.string().email(),
  tenant_role: tenantRoleSchema
})

export const projectsResponseSchema = z.object({
  data: z.array(projectSchema)
}).passthrough()

export const projectResponseSchema = z.object({
  data: projectSchema
})

export const assetsResponseSchema = z.object({
  data: z.array(assetSchema)
}).passthrough()

export const assetResponseSchema = z.object({
  data: assetSchema
})

export const projectUsersResponseSchema = z.object({
  data: z.array(projectUserSchema)
}).passthrough()

export const availableProjectUsersResponseSchema = z.object({
  data: z.array(availableProjectUserSchema)
}).passthrough()

export const projectUserResponseSchema = z.object({
  data: projectUserSchema
})

export type ResourceStatus = z.infer<typeof resourceStatusSchema>
export type AssetType = z.infer<typeof assetTypeSchema>
export type Project = z.infer<typeof projectSchema>
export type Asset = z.infer<typeof assetSchema>
export type ProjectRole = z.infer<typeof projectRoleSchema>
export type ProjectUser = z.infer<typeof projectUserSchema>
export type AvailableProjectUser = z.infer<typeof availableProjectUserSchema>

export interface ProjectInput {
  name: string
  description: string
  status: ResourceStatus
}

export interface ProjectFormInput extends ProjectInput {
  tenant_id: string
}

export interface AssetInput {
  name: string
  code: string
  asset_type: AssetType
  parent_asset_id: string | null
  location: string
  status: ResourceStatus
}

export interface ProjectUserInput {
  user_id: string
  role: ProjectRole
}

export interface ProjectUserAccountInput {
  name: string
  email: string
  password: string
  password_confirmation: string
  role: ProjectRole
}

export interface TenantHierarchy {
  projects: Project[]
  assets: Asset[]
}

export interface AdminTableRow {
  id: string
  name: string
  status: ResourceStatus
  asset_type?: AssetType
  code?: string
  tenant_id?: string
  project_id?: string
  parent_asset_id?: string | null
  description?: string | null
  location?: string | null
  created_at?: string
  updated_at?: string
}
