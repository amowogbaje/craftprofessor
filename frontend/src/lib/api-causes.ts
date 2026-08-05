import { api } from './http'
import type { Cause, CauseBroadcast, CauseMedia, CauseMember, Paginated, SocialProviderKey } from './types'

export async function searchCauses(search?: string) {
  const { data } = await api.get<Paginated<Cause>>('/causes', { params: search ? { search } : undefined })
  return data
}

export async function fetchMyCauses() {
  const { data } = await api.get<{ owned: Cause[]; memberships: (CauseMember & { cause: Cause })[] }>('/causes/mine')
  return data
}

export async function fetchCause(idOrSlug: number | string) {
  const { data } = await api.get<Cause>(`/causes/${idOrSlug}`)
  return data
}

export async function createCause(payload: { title: string; description?: string; goal?: string }) {
  const { data } = await api.post<{ cause: Cause; payment_link: string }>('/causes', payload)
  return data
}

export async function fetchCauseMedia(causeId: number) {
  const { data } = await api.get<CauseMedia[]>(`/causes/${causeId}/media`)
  return data
}

export async function uploadCauseMedia(
  causeId: number,
  payload: { title: string; details?: string; url: string; type: 'image' | 'video'; link_url?: string },
) {
  const { data } = await api.post<CauseMedia>(`/causes/${causeId}/media`, payload)
  return data
}

export async function deleteCauseMedia(causeId: number, mediaId: number) {
  const { data } = await api.delete(`/causes/${causeId}/media/${mediaId}`)
  return data
}

export async function fetchCauseMembers(causeId: number) {
  const { data } = await api.get<CauseMember[]>(`/causes/${causeId}/members`)
  return data
}

export async function inviteToCause(causeId: number, email: string) {
  const { data } = await api.post<CauseMember>(`/causes/${causeId}/invite`, { email })
  return data
}

export async function joinCause(causeId: number) {
  const { data } = await api.post<CauseMember>(`/causes/${causeId}/join`)
  return data
}

export async function optOutOfCause(causeId: number) {
  const { data } = await api.post<CauseMember>(`/causes/${causeId}/opt-out`)
  return data
}

export async function fetchCauseBroadcasts(causeId: number) {
  const { data } = await api.get<CauseBroadcast[]>(`/causes/${causeId}/broadcasts`)
  return data
}

export async function fetchMyCauseBroadcasts() {
  const { data } = await api.get<CauseBroadcast[]>('/my-cause-broadcasts')
  return data
}

export async function scheduleCauseBroadcast(
  causeId: number,
  mediaId: number,
  payload: { user_id: number; provider: SocialProviderKey; scheduled_at: string; timezone: string },
) {
  const { data } = await api.post<CauseBroadcast>(`/causes/${causeId}/media/${mediaId}/broadcasts`, payload)
  return data
}
