import { getRequestHeader, getRequestURL, proxyRequest } from 'h3'

export default defineEventHandler((event) => {
  const requestUrl = getRequestURL(event)
  const proxiedApiPaths = new Set([
    '/api/me',
    '/api/me/password',
    '/api/login',
    '/api/logout',
    '/api/logout-all'
  ])
  const isSanctumCsrfRequest = requestUrl.pathname === '/sanctum/csrf-cookie'
  const isScopedApiRequest = requestUrl.pathname === '/api/tenants'
    || requestUrl.pathname.startsWith('/api/tenants/')
    || requestUrl.pathname.startsWith('/api/platform/')

  if (!proxiedApiPaths.has(requestUrl.pathname) && !isSanctumCsrfRequest && !isScopedApiRequest) {
    return
  }

  const config = useRuntimeConfig(event)
  const target = new URL(requestUrl.pathname + requestUrl.search, config.laravelApiBase)
  const forwardedHeaders: Record<string, string> = {
    Accept: 'application/json',
    origin: getRequestHeader(event, 'origin') ?? requestUrl.origin
  }

  for (const headerName of ['cookie', 'x-xsrf-token', 'referer']) {
    const headerValue = getRequestHeader(event, headerName)

    if (headerValue !== undefined) {
      forwardedHeaders[headerName] = headerValue
    }
  }

  return proxyRequest(event, target.toString(), {
    fetchOptions: {
      headers: forwardedHeaders
    }
  })
})
