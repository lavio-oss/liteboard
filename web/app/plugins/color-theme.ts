import { colorPreferenceSchema, type ColorPreference } from '~/utils/colorTheme'

const colorPreferenceCookieKey = 'liteboard-color-theme'
const colorPreferenceStorageKey = 'liteboard-color-theme'

export default defineNuxtPlugin({
  name: 'color-theme',
  enforce: 'pre',
  setup() {
    const appConfig = useAppConfig()
    const themeCookie = useCookie<ColorPreference | null>(colorPreferenceCookieKey, {
      path: '/',
      maxAge: 60 * 60 * 24 * 365,
      sameSite: 'lax'
    })

    if (themeCookie.value) {
      const parsed = colorPreferenceSchema.safeParse(themeCookie.value)
      if (parsed.success) {
        appConfig.ui.colors.primary = parsed.data.primary
      }
    } else if (import.meta.client) {
      try {
        const stored = localStorage.getItem(colorPreferenceStorageKey)
        if (stored) {
          const parsed = colorPreferenceSchema.safeParse(JSON.parse(stored))
          if (parsed.success) {
            appConfig.ui.colors.primary = parsed.data.primary
            themeCookie.value = parsed.data
          }
        }
      } catch {
        // Ignore parse error
      }
    }

    if (import.meta.client) {
      watch(
        () => ({ primary: appConfig.ui.colors.primary }),
        (preference) => {
          const parsed = colorPreferenceSchema.safeParse(preference)
          if (parsed.success) {
            themeCookie.value = parsed.data
            try {
              localStorage.setItem(colorPreferenceStorageKey, JSON.stringify(parsed.data))
            } catch {
              // Ignore storage errors
            }
          }
        },
        { deep: true }
      )
    }
  }
})
