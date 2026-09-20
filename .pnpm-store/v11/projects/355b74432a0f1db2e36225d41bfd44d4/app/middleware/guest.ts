export default defineNuxtRouteMiddleware(async () => {
  const { load, user } = useAuth()
  await load()

  if (user.value) {
    const tenant = user.value.tenants[0]

    return navigateTo(tenant ? '/tenants/' + tenant.id : '/')
  }
})
