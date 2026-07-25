const TOKEN_KEY = 'storyframe_token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string) {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken() {
  localStorage.removeItem(TOKEN_KEY)
}

/**
 * Fired when the API responds 401 (token missing/expired/revoked) so the
 * app can react (clear auth state, redirect to /login) without axios
 * needing to know about React Router. See App.tsx for the listener.
 */
export const AUTH_LOGOUT_EVENT = 'storyframe:unauthorized'
