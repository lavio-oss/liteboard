import { z } from 'zod'
import { tenantRoleSchema } from '~/types/auth'
import { deviceApprovalSchema } from './deviceApproval'
import { deviceMqttConnectionSchema, deviceMqttTopicsSchema } from './deviceCredential'
import type { deviceProfileVersionDefinitionFieldSchema } from './deviceProfileDefinition'
import { deviceProfileVersionDefinitionSchema } from './deviceProfileDefinition'

export { deviceApprovalStatusSchema } from './deviceApproval'
export { deviceMqttConnectionSchema, deviceMqttTopicsSchema } from './deviceCredential'
export { deviceProfileVersionDefinitionFieldSchema, deviceProfileVersionDefinitionSchema } from './deviceProfileDefinition'

export const resourceStatusSchema = z.enum(['active', 'inactive'])
export const assetTypeSchema = z.enum(['site', 'factory', 'area', 'production_line', 'station', 'equipment'])
export const deviceConnectionStateSchema = z.enum(['online', 'offline', 'unstable'])
export const deviceCredentialTypeSchema = z.enum(['access_token'])
export const deviceCredentialStatusSchema = z.enum(['active', 'revoked'])

const resourceBaseSchema = z.object({
  id: z.string().uuid(),
  name: z.string(),
  status: resourceStatusSchema,
  created_at: z.string(),
  updated_at: z.string()
})

export const projectRoleSchema = z.enum(['admin', 'engineer', 'operator', 'viewer'])

export const projectSchema = resourceBaseSchema.extend({
  tenant_id: z.string().uuid(),
  description: z.string().nullable(),
  role: projectRoleSchema.nullable(),
  max_devices_quota: z.number().int().nonnegative().nullable(),
  device_count: z.number().int().nonnegative()
})

export const projectProvisioningProfileSchema = z.object({
  id: z.string().uuid(),
  project_id: z.string().uuid(),
  enabled: z.boolean(),
  provision_key: z.string(),
  secret_rotated_at: z.string().nullable(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable()
})

export const projectProvisioningProfileResponseSchema = z.object({
  data: projectProvisioningProfileSchema.nullable()
})

export const projectProvisioningSecretResponseSchema = z.object({
  data: projectProvisioningProfileSchema,
  provision_secret: z.string()
})

export const assetSchema = resourceBaseSchema.extend({
  project_id: z.string().uuid(),
  parent_asset_id: z.string().uuid().nullable(),
  asset_type: assetTypeSchema,
  code: z.string(),
  location: z.string().nullable()
})

export const deviceSchema = resourceBaseSchema.extend({
  project_id: z.string().uuid(),
  equipment_asset_id: z.string().uuid().nullable(),
  device_profile_version_id: z.string().uuid().nullable(),
  code: z.string(),
  description: z.string().nullable(),
  connection_state: deviceConnectionStateSchema,
  credential_type: deviceCredentialTypeSchema,
  credential_status: deviceCredentialStatusSchema,
  mqtt_topics: deviceMqttTopicsSchema,
  allow_plaintext: z.boolean(),
  last_seen_at: z.string().nullable()
}).extend(deviceApprovalSchema.shape)

export const deviceProfileVersionSchema = z.object({
  id: z.string().uuid(),
  project_id: z.string().uuid(),
  device_profile_id: z.string().uuid(),
  version: z.number().int().positive(),
  schema_code: z.string(),
  definition: deviceProfileVersionDefinitionSchema,
  compatibility_note: z.string().nullable(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable()
})

export const deviceProfileVersionInputSchema = z.object({
  schema_code: z.string().min(1).max(64),
  definition: deviceProfileVersionDefinitionSchema,
  compatibility_note: z.string().max(1000).nullable()
})

export const deviceProfileSchema = z.object({
  id: z.string().uuid(),
  project_id: z.string().uuid(),
  code: z.string(),
  name: z.string(),
  description: z.string().nullable(),
  versions: z.array(deviceProfileVersionSchema),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable()
})

export const deviceProfileInputSchema = z.object({
  code: z.string().min(1, 'Code is required').max(64, 'Code is too long'),
  name: z.string().min(2, 'Name must be at least 2 characters').max(255, 'Name is too long'),
  description: z.string().max(1000, 'Description is too long').nullable()
})

export const deviceCredentialSchema = z.object({
  device_id: z.string().uuid(),
  project_id: z.string().uuid(),
  credential_type: deviceCredentialTypeSchema,
  access_token: z.string(),
  status: deviceCredentialStatusSchema,
  mqtt: deviceMqttConnectionSchema,
  mqtt_topics: deviceMqttTopicsSchema,
  rotated_at: z.string().nullable(),
  revoked_at: z.string().nullable(),
  updated_at: z.string()
})

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

export const deviceProfilesResponseSchema = z.object({
  data: z.array(deviceProfileSchema)
}).passthrough()

export const deviceProfileResponseSchema = z.object({
  data: deviceProfileSchema
})

export const deviceProfileVersionResponseSchema = z.object({
  data: deviceProfileVersionSchema
})

export const devicesResponseSchema = z.object({
  data: z.array(deviceSchema)
}).passthrough()

export const deviceResponseSchema = z.object({
  data: deviceSchema
})

export const deviceCredentialResponseSchema = z.object({
  data: deviceCredentialSchema
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
export type DeviceConnectionState = z.infer<typeof deviceConnectionStateSchema>
export type DeviceApprovalStatus = z.infer<typeof deviceApprovalSchema.shape.approval_status>
export type DeviceCredentialType = z.infer<typeof deviceCredentialTypeSchema>
export type DeviceCredentialStatus = z.infer<typeof deviceCredentialStatusSchema>
export type DeviceMqttTopics = z.infer<typeof deviceMqttTopicsSchema>
export type Project = z.infer<typeof projectSchema>
export type Asset = z.infer<typeof assetSchema>
export type DeviceProfile = z.infer<typeof deviceProfileSchema>
export type DeviceProfileVersion = z.infer<typeof deviceProfileVersionSchema>
export type DeviceProfileVersionDefinition = z.infer<typeof deviceProfileVersionDefinitionSchema>
export type DeviceProfileVersionFieldType = z.infer<typeof deviceProfileVersionDefinitionFieldSchema>['type']
export type DeviceProfileVersionInput = z.infer<typeof deviceProfileVersionInputSchema>
export type Device = z.infer<typeof deviceSchema>
export type DeviceCredential = z.infer<typeof deviceCredentialSchema>
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

export type DeviceProfileInput = z.infer<typeof deviceProfileInputSchema>

export interface DeviceInput {
  name: string
  code: string
  description: string | null
  equipment_asset_id: string | null
  device_profile_version_id: string | null
  status: ResourceStatus
  allow_plaintext?: boolean
}

export type ProjectProvisioningProfile = z.infer<typeof projectProvisioningProfileSchema>
export type ProjectProvisioningSecretResponse = z.infer<typeof projectProvisioningSecretResponseSchema>

export interface DeviceCredentialInput {
  status: DeviceCredentialStatus
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

// Minimum shape AdminTable needs to search and list a row.
export interface SearchableRow {
  id: string
  name: string
  code?: string
  status?: string
}

export interface AdminTableRow {
  id: string
  name: string
  status?: ResourceStatus
  asset_type?: AssetType
  code?: string
  tenant_id?: string
  project_id?: string
  parent_asset_id?: string | null
  equipment_asset_id?: string | null
  device_profile_version_id?: string | null
  description?: string | null
  location?: string | null
  connection_state?: DeviceConnectionState
  approval_status?: DeviceApprovalStatus
  approval_reason?: string | null
  credential_type?: DeviceCredentialType
  credential_status?: DeviceCredentialStatus
  mqtt_topics?: DeviceMqttTopics
  last_seen_at?: string | null
  created_at?: string | null
  updated_at?: string | null
}
