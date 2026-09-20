export default defineNuxtRouteMiddleware(async () => {
  const { load, user } = useAuth()
  await load()

  if (!user.value) {
    return navigateTo('/login')
  }
})
