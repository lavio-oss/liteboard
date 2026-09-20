import { z } from 'zod'
import { ApiError, useApi } from '~/composables/useApi'
import { authUserResponseSchema } from '~/types/auth'
import type { AuthUser } from '~/types/auth'

const emptyResponseSchema = z.unknown()

export type AuthStatus = 'authenticated' | 'guest' | 'loading' | 'unknown'

export interface LoginCredentials {
  email: string
  password: string
}

export function useAuth() {
  const api = useApi()
  const user = useState<AuthUser | null>('auth:user', () => null)
  const status = useState<AuthStatus>('auth:status', () => 'unknown')

  async function load(force = false): Promise<AuthUser | null> {
    if (!force && (status.value === 'authenticated' || status.value === 'guest')) {
      return user.value
    }

    status.value = 'loading'

    try {
      const response = await api.request('/api/me', authUserResponseSchema)
      user.value = response.data
      status.value = 'authenticated'

      return user.value
    } catch (error: unknown) {
      if (error instanceof ApiError && error.status === 401) {
        user.value = null
        status.value = 'guest'

        return null
      }

      status.value = 'unknown'
      throw error
    }
  }

  async function login(credentials: LoginCredentials): Promise<AuthUser> {
    status.value = 'loading'

    try {
      await api.request('/sanctum/csrf-cookie', emptyResponseSchema)
      const response = await api.request('/api/login', authUserResponseSchema, {
        body: credentials,
        method: 'POST'
      })

      user.value = response.data
      status.value = 'authenticated'

      return response.data
    } catch (error: unknown) {
      user.value = null
      status.value = 'guest'
      throw error
    }
  }

  async function logout(): Promise<void> {
    try {
      await api.request('/api/logout', emptyResponseSchema, { method: 'POST' })
    } finally {
      user.value = null
      status.value = 'guest'
    }
  }

  async function logoutAll(): Promise<void> {
    try {
      await api.request('/api/logout-all', emptyResponseSchema, { method: 'POST' })
    } finally {
      user.value = null
      status.value = 'guest'
    }
  }

  return {
    load,
    login,
    logout,
    logoutAll,
    status,
    user
  }
}
