import { z } from 'zod'

export const tenantRoleSchema = z.enum(['owner', 'admin', 'member'])
export const systemRoleSchema = z.literal('platform_admin').nullable()
export const tenantStatusSchema = z.enum(['active', 'inactive'])

export const tenantSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  code: z.string(),
  status: tenantStatusSchema,
  role: tenantRoleSchema.nullable()
})

export const tenantMembershipSchema = tenantSchema.pick({
  id: true,
  name: true,
  code: true
}).extend({ role: tenantRoleSchema })

export const authUserSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  email: z.string().email(),
  email_verified_at: z.string().nullable(),
  system_role: systemRoleSchema,
  tenants: z.array(tenantMembershipSchema)
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
  id: z.number().int().positive(),
  name: z.string(),
  email: z.string().email(),
  role: tenantRoleSchema,
  system_role: systemRoleSchema
})

export const tenantUserResponseSchema = z.object({
  data: tenantUserSchema
})

export const tenantUsersResponseSchema = z.object({
  data: z.array(tenantUserSchema)
}).passthrough()

export type TenantUserCreateInput = {
  name: string
  email: string
  password: string
  password_confirmation: string
}

export type TenantUserUpdateInput = {
  name: string
  email: string
  password?: string
  password_confirmation?: string
}

export type TenantRole = z.infer<typeof tenantRoleSchema>
export type SystemRole = z.infer<typeof systemRoleSchema>
export type Tenant = z.infer<typeof tenantSchema>
export type TenantMembership = z.infer<typeof tenantMembershipSchema>
export type AuthUser = z.infer<typeof authUserSchema>
export type TenantUser = z.infer<typeof tenantUserSchema>
