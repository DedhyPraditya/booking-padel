import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import type { AuthenticatedUser } from '@/types/api'
import { apiRequest, getStoredToken, setStoredToken } from '@/services/apiClient'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthenticatedUser | null>(null)
  const loading = ref(false)
  const initialized = ref(false)

  const isAuthenticated = computed(() => user.value !== null)

  function hasPermission(permission: string): boolean {
    if (!user.value) return false
    if (user.value.roles.some((r) => r.role === 'super_admin')) return true
    return user.value.permissions.includes(permission)
  }

  async function hydrate(): Promise<void> {
    if (initialized.value) return
    const token = getStoredToken()
    if (!token) {
      initialized.value = true
      return
    }
    loading.value = true
    try {
      const response = await apiRequest<AuthenticatedUser>('/me')
      user.value = response.data
    } catch {
      setStoredToken(null)
      user.value = null
    } finally {
      loading.value = false
      initialized.value = true
    }
  }

  async function login(email: string, password: string): Promise<void> {
    loading.value = true
    try {
      const response = await apiRequest<{ token: string; user: AuthenticatedUser }>('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password, device_name: 'web-browser' }),
      })
      setStoredToken(response.data.token)
      user.value = response.data.user
      initialized.value = true
    } finally {
      loading.value = false
    }
  }

  async function logout(): Promise<void> {
    try {
      await apiRequest('/auth/logout', { method: 'POST' })
    } finally {
      setStoredToken(null)
      user.value = null
    }
  }

  return {
    user,
    loading,
    initialized,
    isAuthenticated,
    hasPermission,
    hydrate,
    login,
    logout,
  }
})
