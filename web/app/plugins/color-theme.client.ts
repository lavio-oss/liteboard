import { colorPreferenceSchema } from '~/utils/colorTheme'

const colorPreferenceStorageKey = 'liteboard-color-theme'

export default defineNuxtPlugin(() => {
  const appConfig = useAppConfig()
  const storedPreference = localStorage.getItem(colorPreferenceStorageKey)

  if (storedPreference !== null) {
    const preference = colorPreferenceSchema.parse(JSON.parse(storedPreference) as unknown)
    appConfig.ui.colors.primary = preference.primary
    appConfig.ui.colors.neutral = preference.neutral
  }

  watch(() => ({
    primary: appConfig.ui.colors.primary,
    neutral: appConfig.ui.colors.neutral
  }), (preference) => {
    localStorage.setItem(colorPreferenceStorageKey, JSON.stringify(colorPreferenceSchema.parse(preference)))
  })
})
