import { getBrandLogoDataUri } from '~/utils/colorTheme'

export function useBrandLogo() {
  const appConfig = useAppConfig()
  const colorMode = useColorMode()

  return computed(() => getBrandLogoDataUri(appConfig.ui.colors.primary, colorMode.value === 'dark'))
}
