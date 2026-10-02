import * as z from 'zod'

export const primaryColors = [
  'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'
] as const

export const neutralColors = ['slate', 'gray', 'zinc', 'neutral', 'stone', 'taupe', 'mauve', 'mist', 'olive'] as const

export const colorPreferenceSchema = z.object({
  primary: z.enum(primaryColors),
  neutral: z.enum(neutralColors)
})
