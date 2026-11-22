import type { ApiEnvelope, ApiValidationError } from '@/types/api'

const TOKEN_STORAGE_KEY = 'padel_auth_token'
const TOKEN_EXPIRES_KEY = 'padel_auth_expires_at'
const TOKEN_DAY_KEY = 'padel_auth_wita_day'

export class ApiError extends Error {
  status: number
  errors?: Record<string, string[]>

  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

function currentWitaDay(): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Makassar',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

export function getStoredToken(): string | null {
  const token = localStorage.getItem(TOKEN_STORAGE_KEY)
  if (!token) return null

  const storedDay = localStorage.getItem(TOKEN_DAY_KEY)
  const storedExpiresAt = localStorage.getItem(TOKEN_EXPIRES_KEY)

  // Force re-login if the calendar day in WITA has changed or if expires_at has passed
  if (storedDay && storedDay !== currentWitaDay()) {
    setStoredToken(null)
    return null
  }

  if (storedExpiresAt && new Date(storedExpiresAt).getTime() <= Date.now()) {
    setStoredToken(null)
    return null
  }

  return token
}

export function setStoredToken(token: string | null, expiresAt?: string | null): void {
  if (token) {
    localStorage.setItem(TOKEN_STORAGE_KEY, token)
    localStorage.setItem(TOKEN_DAY_KEY, currentWitaDay())
    if (expiresAt) {
      localStorage.setItem(TOKEN_EXPIRES_KEY, expiresAt)
    } else {
      localStorage.removeItem(TOKEN_EXPIRES_KEY)
    }
  } else {
    localStorage.removeItem(TOKEN_STORAGE_KEY)
    localStorage.removeItem(TOKEN_DAY_KEY)
    localStorage.removeItem(TOKEN_EXPIRES_KEY)
  }
}

export async function apiRequest<T>(
  path: string,
  options: RequestInit = {},
): Promise<ApiEnvelope<T>> {
  const token = getStoredToken()
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')

  if (options.body && !(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }

  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(`/api/v1${path}`, {
    ...options,
    headers,
  })

  if (!response.ok) {
    if (response.status === 401) {
      setStoredToken(null)
    }
    let payload: ApiValidationError | null = null
    try {
      payload = (await response.json()) as ApiValidationError
    } catch {
      payload = null
    }
    throw new ApiError(
      payload?.message || `Permintaan gagal dengan kode HTTP ${response.status}.`,
      response.status,
      payload?.errors,
    )
  }

  return (await response.json()) as ApiEnvelope<T>
}
