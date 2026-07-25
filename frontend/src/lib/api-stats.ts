import { api } from './http'
import type { LinkStats } from './types'

export async function fetchLinkStats(from?: string, to?: string) {
  const { data } = await api.get<LinkStats>('/link-stats', { params: { from, to } })
  return data
}
