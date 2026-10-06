import { z } from 'zod'

export const deviceApprovalStatusSchema = z.enum(['pending', 'approved', 'rejected'])

export const deviceApprovalSchema = z.object({
  approval_status: deviceApprovalStatusSchema,
  approval_reason: z.string().nullable()
})
