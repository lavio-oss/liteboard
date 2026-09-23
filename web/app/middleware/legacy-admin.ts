const adminRedirects: Record<string, string> = {
  '/iiot-tree': '/admin/iiot-tree',
  '/projects': '/admin/projects',
  '/tenants': '/admin/tenants',
  '/users': '/admin/users'
}

export default defineNuxtRouteMiddleware((to) => {
  const target = adminRedirects[to.path]

  if (target === undefined) {
    throw new Error(`No Admin redirect is configured for ${to.path}.`)
  }

  return navigateTo(target, { redirectCode: 301 })
})
