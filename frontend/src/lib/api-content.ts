import { api } from './http'
import type {
  CharacterDetailResponse,
  CharactersResponse,
  ContentStatus,
  FeedItem,
  Paginated,
  SeriesDetailResponse,
  Story,
  StoryDetailResponse,
  StorySeries,
  StoryVerseImportResult,
  StoryVideo,
} from './types'

export async function fetchFeed(type: 'all' | 'image' | 'video', status: 'all' | ContentStatus) {
  const { data } = await api.get<{ data: FeedItem[] }>('/dashboard/feed', { params: { type, status } })
  return data.data
}

export async function updateFeedItemStatus(
  item: Pick<FeedItem, 'type' | 'id'>,
  status: ContentStatus,
  scheduledAt?: string,
) {
  const path = item.type === 'image' ? `/dashboard/images/${item.id}` : `/dashboard/videos/${item.id}`
  const { data } = await api.patch<FeedItem>(path, { status, scheduled_at: scheduledAt })
  return data
}

export async function deleteFeedItem(item: Pick<FeedItem, 'type' | 'id'>) {
  const { data } = await api.delete<{ message: string }>('/dashboard/media', {
    data: { type: item.type, resource_id: item.id },
  })
  return data
}

export async function requestVideo(imagePromptId: number) {
  // 'sync' mode returns 201 with the finished video already in `data`;
  // 'queue' mode returns 202 with just a queued message — same call site
  // either way, see config('ai.video_generation_mode') on the backend.
  const { data } = await api.post<{ message: string; data?: { id: number; video_url: string } }>(
    `/story-image-prompts/${imagePromptId}/video`
  )
  return data
}

export async function regenerateVideo(imagePromptId: number) {
  const { data } = await api.post<{ message: string; data?: { id: number; video_url: string } }>(
    `/story-image-prompts/${imagePromptId}/video/regenerate`
  )
  return data
}

export async function fetchStories(page = 1) {
  const { data } = await api.get<Paginated<Story>>('/stories', { params: { page } })
  return data
}

export async function submitStory(text: string, storyLink?: string) {
  const { data } = await api.post<{ message: string; story: Story }>('/stories', {
    text,
    story_link: storyLink || undefined,
  })
  return data
}

export async function fetchSeries(page = 1) {
  const { data } = await api.get<Paginated<StorySeries>>('/story-series', { params: { page } })
  return data
}

export async function fetchSeriesDetail(seriesSlug: string) {
  const { data } = await api.get<SeriesDetailResponse>(`/series/${seriesSlug}`)
  return data
}

export async function createSeries(links: string[], title?: string, description?: string) {
  const { data } = await api.post<{ series: StorySeries }>('/story-series', { links, title, description })
  return data
}

export async function importStoryVerseSeries(url: string) {
  const { data } = await api.post<StoryVerseImportResult>('/story-series/import-storyverse', { url })
  return data
}

export async function requestStoryVideo(storyId: number) {
  const { data } = await api.post<{ message: string; data: StoryVideo }>(`/stories/${storyId}/video`)
  return data
}

export async function fetchStoryVideo(storyId: number) {
  const { data } = await api.get<{ data: StoryVideo }>(`/stories/${storyId}/video`)
  return data.data
}

export async function publishStoryVideoToPinterest(storyId: number) {
  const { data } = await api.post<{ message: string; data: StoryVideo }>(`/stories/${storyId}/video/publish-pinterest`)
  return data
}

export async function fetchStoryDetail(storySlug: string) {
  const { data } = await api.get<StoryDetailResponse>(`/stories/${storySlug}`)
  return data
}

export async function fetchStoryCharacters(storySlug: string) {
  const { data } = await api.get<CharactersResponse>(`/stories/${storySlug}/characters`)
  return data
}

export async function fetchSeriesCharacters(seriesSlug: string) {
  const { data } = await api.get<CharactersResponse>(`/series/${seriesSlug}/characters`)
  return data
}

export async function fetchCharacterDetail(characterId: number) {
  const { data } = await api.get<CharacterDetailResponse>(`/characters/${characterId}`)
  return data
}
