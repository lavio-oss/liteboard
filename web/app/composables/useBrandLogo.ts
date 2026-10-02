import { getBrandLogoDataUri } from '~/utils/colorTheme'

export function useBrandLogo() {
  const appConfig = useAppConfig()

  return computed(() => getBrandLogoDataUri(appConfig.ui.colors.primary))
}
