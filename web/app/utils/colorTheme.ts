import * as z from 'zod'
import colors from 'tailwindcss/colors'

export const primaryColors = [
  'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'
] as const

export const neutralColors = ['slate', 'gray', 'zinc', 'neutral', 'stone', 'taupe', 'mauve', 'mist', 'olive'] as const

export const colorPreferenceSchema = z.object({
  primary: z.enum(primaryColors),
  neutral: z.enum(neutralColors)
})

export type ColorPreference = z.infer<typeof colorPreferenceSchema>

export function getBrandLogoDataUri(primaryColor: string): string {
  const color = colorPreferenceSchema.shape.primary.parse(primaryColor)
  const stroke = color === 'green' ? '#00c16a' : colors[color][500]
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><g stroke="${stroke}" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M12 20v2m0-20v2m5 16v2m0-20v2M2 12h2m-2 5h2M2 7h2m16 5h2m-2 5h2M20 7h2M7 20v2M7 2v2"/><rect width="16" height="16" x="4" y="4" rx="2"/><rect width="8" height="8" x="8" y="8" rx="1"/></g></svg>`

  return `data:image/svg+xml,${encodeURIComponent(svg)}`
}
