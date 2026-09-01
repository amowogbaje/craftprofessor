import { api } from './http'
import type { AppNotification } from './types'

export async function fetchNotifications(unreadOnly = false) {
  const { data } = await api.get<{ data: AppNotification[]; unread_count: number }>('/notifications', {
    params: unreadOnly ? { unread: 1 } : undefined,
  })
  return data
}

export async function markNotificationRead(id: string) {
  const { data } = await api.post<{ message: string }>(`/notifications/${id}/read`)
  return data
}

export async function markAllNotificationsRead() {
  const { data } = await api.post<{ message: string }>('/notifications/read-all')
  return data
}
