import { api } from './http'
import type { ContentStatus, FeedItem } from './types'

export type FeedType = 'all' | 'image' | 'video'
export type FeedSort = 'newest' | 'oldest' | 'scheduled_at'

export interface FeedQuery {
  type: FeedType
  status: 'all' | ContentStatus
  sort: FeedSort
  search: string
  cursor: string | null
}

export interface FeedPage {
  data: FeedItem[]
  next_cursor: string | null
}

export async function fetchFeedPage(q: FeedQuery): Promise<FeedPage> {
  const { data } = await api.get<FeedPage>('/dashboard/feed', {
    params: {
      type: q.type,
      status: q.status,
      sort: q.sort,
      search: q.search || undefined,
      cursor: q.cursor || undefined,
      per_page: 24,
    },
  })
  return data
}
