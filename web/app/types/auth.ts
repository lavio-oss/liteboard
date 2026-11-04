import { z } from 'zod'

export const roleScopeSchema = z.enum(['tenant', 'project'])
export const tenantStatusSchema = z.enum(['active', 'inactive'])

export const roleSchema = z.object({
  id: z.string().uuid(),
  scope: roleScopeSchema,
  key: z.string().nullable(),
  name: z.string(),
  is_system: z.boolean(),
  is_owner: z.boolean(),
  // null for the default roles the system seeds.
  created_by: z.object({ id: z.string().uuid(), name: z.string() }).nullable(),
  created_at: z.string(),
  permissions: z.array(z.string()),
  locked_permissions: z.array(z.string())
})

export const permissionDefinitionSchema = z.object({
  key: z.string(),
  scope: roleScopeSchema
})

export const tenantSchema = z.object({
  id: z.string().uuid(),
  name: z.string(),
  code: z.string(),
  status: tenantStatusSchema,
  // The requesting member's tenant role and their effective tenant permissions.
  role: roleSchema,
  permissions: z.array(z.string()),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable()
})

export const authUserSchema = z.object({
  id: z.string().uuid(),
  name: z.string(),
  email: z.string().email(),
  email_verified_at: z.string().nullable(),
  tenants: z.array(tenantSchema)
})

export const authUserResponseSchema = z.object({
  data: authUserSchema
})

export const tenantCollectionResponseSchema = z.object({
  data: z.array(tenantSchema)
})

export const tenantResponseSchema = z.object({
  data: tenantSchema
})

export const tenantUserSchema = z.object({
  id: z.string().uuid(),
  name: z.string(),
  email: z.string().email(),
  role_id: z.string().uuid()
})

export const tenantUserResponseSchema = z.object({
  data: tenantUserSchema
})

export const tenantUsersResponseSchema = z.object({
  data: z.array(tenantUserSchema)
}).passthrough()

export const rolesResponseSchema = z.object({
  data: z.array(roleSchema)
})

export const roleResponseSchema = z.object({
  data: roleSchema
})

export const permissionDefinitionsResponseSchema = z.object({
  data: z.array(permissionDefinitionSchema)
})

export type TenantUserCreateInput = {
  name: string
  email: string
  password: string
  password_confirmation: string
  role_id: string
}

export type TenantUserUpdateInput = {
  name: string
  email: string
  password?: string
  password_confirmation?: string
  role_id?: string
}

export type RoleInput = {
  name: string
  scope: RoleScope
  permissions: string[]
}

export type RoleScope = z.infer<typeof roleScopeSchema>
export type Role = z.infer<typeof roleSchema>
export type PermissionDefinition = z.infer<typeof permissionDefinitionSchema>
export type Tenant = z.infer<typeof tenantSchema>
export type AuthUser = z.infer<typeof authUserSchema>
export type TenantUser = z.infer<typeof tenantUserSchema>
