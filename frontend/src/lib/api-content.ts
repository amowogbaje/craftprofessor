import { api } from './http'
import type { ContentStatus, FeedItem, Paginated, Story, StorySeries, StoryVerseImportResult, StoryVideo } from './types'

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
  const { data } = await api.post<{ message: string }>(`/story-image-prompts/${imagePromptId}/video`)
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
