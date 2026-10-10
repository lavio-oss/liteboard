import { z } from 'zod'

export const deviceProfileVersionDefinitionFieldSchema = z.object({
  type: z.enum(['integer', 'float', 'string', 'boolean']),
  unit: z.string().min(1).max(64),
  required: z.boolean(),
  min: z.number().optional(),
  max: z.number().optional(),
  allowed_values: z.array(z.string().min(1).max(64)).min(1).max(100).optional()
}).strict().superRefine((field, context) => {
  const numeric = field.type === 'integer' || field.type === 'float'

  if (!numeric && (field.min !== undefined || field.max !== undefined)) {
    context.addIssue({
      code: 'custom',
      path: ['min'],
      message: 'Minimum and maximum are only supported for numeric fields'
    })
  }

  if (field.min !== undefined && field.max !== undefined && field.min > field.max) {
    context.addIssue({
      code: 'custom',
      path: ['max'],
      message: 'Maximum must be greater than or equal to minimum'
    })
  }

  if (field.allowed_values !== undefined) {
    if (field.type !== 'string') {
      context.addIssue({
        code: 'custom',
        path: ['allowed_values'],
        message: 'Allowed values are only supported for string fields'
      })
    }

    const values = new Set<string>()

    field.allowed_values.forEach((value, index) => {
      if (value !== value.trim()) {
        context.addIssue({
          code: 'custom',
          path: ['allowed_values', index],
          message: 'Allowed values cannot have leading or trailing spaces'
        })
      }

      if (values.has(value)) {
        context.addIssue({
          code: 'custom',
          path: ['allowed_values', index],
          message: 'Allowed values must be unique'
        })
      }
      values.add(value)
    })
  }
})

export const deviceProfileVersionDefinitionSchema = z.object({
  strict_mode: z.boolean(),
  fields: z.record(z.string(), deviceProfileVersionDefinitionFieldSchema).refine(
    fields => Object.keys(fields).length > 0,
    'At least one schema field is required'
  )
}).strict().superRefine((definition, context) => {
  for (const fieldName of Object.keys(definition.fields)) {
    if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(fieldName)) {
      context.addIssue({
        code: 'custom',
        path: ['fields', fieldName],
        message: 'Field names must start with a letter and use only letters, numbers, and underscores'
      })
    }
  }
})
