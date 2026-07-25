import { api } from './http'

export interface SocialAccount {
  id: number
  provider: 'pinterest' | 'youtube' | 'linkedin'
  provider_username: string | null
  scopes: string[]
  connected_at: string | null
}

export async function fetchSocialAccounts(): Promise<{ data: SocialAccount[] }> {
  const { data } = await api.get('/social/accounts')
  return data
}

export async function getPinterestConnectUrl(): Promise<string> {
  const { data } = await api.get<{ url: string }>('/social/pinterest/connect')
  return data.url
}

export async function disconnectSocialAccount(provider: string): Promise<void> {
  await api.delete(`/social/accounts/${provider}`)
}