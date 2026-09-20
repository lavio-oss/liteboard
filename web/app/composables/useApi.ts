import { FetchError } from 'ofetch'
import { z } from 'zod'

export type ApiMethod = 'DELETE' | 'GET' | 'PATCH' | 'POST' | 'PUT'

export interface ApiRequestOptions {
  body?: unknown
  headers?: Record<string, string>
  method?: ApiMethod
}

interface ApiErrorPayload {
  errors?: Record<string, string[]>
  message?: string
}

export class ApiError extends Error {
  readonly errors: Record<string, string[]>
  readonly status: number

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.name = 'ApiError'
    this.errors = errors
    this.status = status
  }
}

const apiErrorPayloadSchema = z.object({
  errors: z.record(z.string(), z.array(z.string())).optional(),
  message: z.string().optional()
})

function parseApiErrorPayload(payload: unknown): ApiErrorPayload {
  const result = apiErrorPayloadSchema.safeParse(payload)

  return result.success ? result.data : {}
}

export function useApi() {
  const serverHeaders = import.meta.server
    ? useRequestHeaders(['cookie', 'x-xsrf-token', 'origin', 'referer'])
    : undefined
  const xsrfToken = useCookie<string | null>('XSRF-TOKEN')

  async function request<T>(path: string, schema: z.ZodType<T>, options: ApiRequestOptions = {}): Promise<T> {
    try {
      const fetcher = import.meta.server ? useRequestFetch() : $fetch
      const requestHeaders = new Headers(serverHeaders)

      if (xsrfToken.value !== null && xsrfToken.value !== undefined && xsrfToken.value !== '') {
        requestHeaders.set('X-XSRF-TOKEN', decodeURIComponent(xsrfToken.value))
      }

      const headers: Record<string, string> = {
        Accept: 'application/json',
        ...Object.fromEntries(requestHeaders.entries()),
        ...options.headers
      }
      const body = options.body === undefined ? undefined : JSON.stringify(options.body)

      if (body !== undefined) {
        headers['Content-Type'] = 'application/json'
      }

      const response = await fetcher<unknown>(path, {
        body,
        credentials: 'include',
        headers,
        method: options.method
      })

      return schema.parse(response)
    } catch (error: unknown) {
      if (error instanceof z.ZodError) {
        throw new ApiError(500, 'The server returned an invalid response.')
      }

      if (error instanceof FetchError) {
        const payload = parseApiErrorPayload(error.response?._data)

        throw new ApiError(
          error.response?.status ?? 0,
          payload.message ?? 'The request failed.',
          payload.errors ?? {}
        )
      }

      throw error
    }
  }

  return { request }
}
