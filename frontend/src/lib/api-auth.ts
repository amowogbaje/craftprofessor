import { api } from './http'
import type { OtpChannel, OtpPurpose, User } from './types'

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  phone?: string
  otp_channel: OtpChannel
}

export async function register(payload: RegisterPayload) {
  const { data } = await api.post<{ message: string; user_id: number }>('/auth/register', payload)
  return data
}

export async function login(email: string, password: string) {
  const { data } = await api.post<{ token: string; user: User }>('/auth/login', { email, password })
  return data
}

export async function resendOtp(email: string, otp_channel: OtpChannel, purpose: OtpPurpose) {
  const { data } = await api.post<{ message: string }>('/auth/otp/resend', { email, otp_channel, purpose })
  return data
}

export async function verifySignupOtp(email: string, code: string) {
  const { data } = await api.post<{ message: string; token: string; user: User }>('/auth/otp/verify', {
    email,
    code,
    purpose: 'signup',
  })
  return data
}

export async function verifyPasswordResetOtp(email: string, code: string) {
  const { data } = await api.post<{ message: string; reset_token: string }>('/auth/otp/verify', {
    email,
    code,
    purpose: 'password_reset',
  })
  return data
}

export async function forgotPassword(email: string, otp_channel: OtpChannel) {
  const { data } = await api.post<{ message: string }>('/auth/password/forgot', { email, otp_channel })
  return data
}

export async function resetPassword(resetToken: string, password: string, password_confirmation: string) {
  const { data } = await api.post<{ message: string; token: string }>(
    '/auth/password/reset',
    { password, password_confirmation },
    { headers: { Authorization: `Bearer ${resetToken}` } },
  )
  return data
}

export async function logout() {
  await api.post('/auth/logout')
}

export async function fetchCurrentUser() {
  const { data } = await api.get<User>('/user')
  return data
}

/** Full-page navigation (not a fetch) — Google's own redirect flow needs the real browser URL bar. */
export function googleLoginUrl(): string {
  const apiBase = import.meta.env.VITE_API_URL ?? 'https://craftprofessor.amowogbaje.com/api'
  return `${apiBase}/auth/google/redirect`
}
