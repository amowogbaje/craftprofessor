import { api } from './http'

export type SocialProvider = 'pinterest' | 'linkedin' | 'twitter' | 'youtube' | 'instagram' | 'facebook'

export interface SocialAccount {
  id: number
  provider: SocialProvider
  provider_username: string | null
  scopes: string[]
  connected_at: string | null
}

export async function fetchSocialAccounts(): Promise<{
  data: SocialAccount[]
  configured_providers: Record<SocialProvider, boolean>
}> {
  const { data } = await api.get('/social/accounts')
  return data
}

export async function getPinterestConnectUrl(): Promise<string> {
  const { data } = await api.get<{ url: string }>('/social/pinterest/connect')
  return data.url
}

/** Generic connect flow for every provider except Pinterest (see SocialOAuthController). */
export async function getConnectUrl(provider: Exclude<SocialProvider, 'pinterest'>): Promise<string> {
  const { data } = await api.get<{ url: string }>(`/social/${provider}/connect`)
  return data.url
}

export async function disconnectSocialAccount(provider: string): Promise<void> {
  await api.delete(`/social/accounts/${provider}`)
}
