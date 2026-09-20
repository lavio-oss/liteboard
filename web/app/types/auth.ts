import { z } from 'zod'

export const tenantRoleSchema = z.enum(['owner', 'admin', 'member'])
export const tenantStatusSchema = z.enum(['active', 'inactive'])

export const tenantSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  code: z.string(),
  status: tenantStatusSchema,
  role: tenantRoleSchema
})

export const tenantMembershipSchema = tenantSchema.pick({
  id: true,
  name: true,
  code: true,
  role: true
})

export const authUserSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  email: z.string().email(),
  email_verified_at: z.string().nullable(),
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

export type TenantRole = z.infer<typeof tenantRoleSchema>
export type Tenant = z.infer<typeof tenantSchema>
export type TenantMembership = z.infer<typeof tenantMembershipSchema>
export type AuthUser = z.infer<typeof authUserSchema>
