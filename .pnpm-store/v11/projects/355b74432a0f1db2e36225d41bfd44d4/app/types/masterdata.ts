import { z } from 'zod'

export const masterdataStatusSchema = z.enum(['active', 'inactive'])

const resourceBaseSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  status: masterdataStatusSchema,
  created_at: z.string(),
  updated_at: z.string()
})

export const projectSchema = resourceBaseSchema.extend({
  tenant_id: z.number().int().positive(),
  description: z.string().nullable()
})

export const factorySchema = resourceBaseSchema.extend({
  project_id: z.number().int().positive(),
  code: z.string(),
  location: z.string().nullable()
})

export const productionLineSchema = resourceBaseSchema.extend({
  factory_id: z.number().int().positive(),
  code: z.string()
})

export const projectsResponseSchema = z.object({
  data: z.array(projectSchema)
}).passthrough()

export const projectResponseSchema = z.object({
  data: projectSchema
})

export const factoriesResponseSchema = z.object({
  data: z.array(factorySchema)
}).passthrough()

export const factoryResponseSchema = z.object({
  data: factorySchema
})

export const productionLinesResponseSchema = z.object({
  data: z.array(productionLineSchema)
}).passthrough()

export const productionLineResponseSchema = z.object({
  data: productionLineSchema
})

export type MasterdataStatus = z.infer<typeof masterdataStatusSchema>
export type Project = z.infer<typeof projectSchema>
export type Factory = z.infer<typeof factorySchema>
export type ProductionLine = z.infer<typeof productionLineSchema>

export interface ProjectInput {
  name: string
  description: string
  status: MasterdataStatus
}

export interface FactoryInput {
  name: string
  code: string
  location: string
  status: MasterdataStatus
}

export interface ProductionLineInput {
  name: string
  code: string
  status: MasterdataStatus
}

export interface TenantHierarchy {
  projects: Project[]
  factories: Factory[]
  productionLines: ProductionLine[]
}

export interface MasterdataRow {
  id: number
  name: string
  status: MasterdataStatus
  code?: string
  tenant_id?: number
  project_id?: number
  factory_id?: number
  description?: string | null
  location?: string | null
  created_at?: string
  updated_at?: string
}
