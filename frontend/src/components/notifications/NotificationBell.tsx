import { useEffect, useRef, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Bell, CheckCircle2, XCircle, CheckCheck } from 'lucide-react'
import { fetchNotifications, markAllNotificationsRead, markNotificationRead } from '@/lib/api-notifications'
import { cn } from '@/lib/utils'
import type { AppNotification } from '@/lib/types'

function timeAgo(iso: string): string {
  const diffMs = Date.now() - new Date(iso).getTime()
  const minutes = Math.floor(diffMs / 60000)
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  return `${Math.floor(hours / 24)}d ago`
}

function NotificationRow({ notification, onRead }: { notification: AppNotification; onRead: (id: string) => void }) {
  const unread = !notification.read_at
  const succeeded = notification.data.succeeded

  return (
    <button
      type="button"
      onClick={() => unread && onRead(notification.id)}
      className={cn(
        'flex w-full gap-2.5 border-b border-border px-4 py-3 text-left transition-colors last:border-b-0',
        unread ? 'bg-secondary/50' : 'hover:bg-secondary/30',
      )}
    >
      {succeeded === false ? (
        <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
      ) : (
        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-success" />
      )}
      <div className="min-w-0 flex-1">
        <p className="text-sm">{notification.data.message}</p>
        <p className="mt-0.5 text-xs text-muted-foreground">{timeAgo(notification.created_at)}</p>
      </div>
      {unread && <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />}
    </button>
  )
}

export function NotificationBell() {
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['notifications'],
    queryFn: () => fetchNotifications(),
    refetchInterval: 30000,
  })

  const markRead = useMutation({
    mutationFn: (id: string) => markNotificationRead(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const markAllRead = useMutation({
    mutationFn: () => markAllNotificationsRead(),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const unreadCount = query.data?.unread_count ?? 0
  const notifications = query.data?.data ?? []

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="relative rounded-md p-2 text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
        title="Notifications"
      >
        <Bell className="h-4 w-4" />
        {unreadCount > 0 && (
          <span className="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-medium text-destructive-foreground">
            {unreadCount > 9 ? '9+' : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 top-full z-50 mt-2 w-80 overflow-hidden rounded-md border border-border bg-card shadow-lg">
          <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
            <span className="text-sm font-medium">Notifications</span>
            {unreadCount > 0 && (
              <button
                type="button"
                onClick={() => markAllRead.mutate()}
                className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
              >
                <CheckCheck className="h-3 w-3" />
                Mark all read
              </button>
            )}
          </div>
          <div className="max-h-96 overflow-y-auto">
            {notifications.length > 0 ? (
              notifications.map((n) => (
                <NotificationRow key={n.id} notification={n} onRead={(id) => markRead.mutate(id)} />
              ))
            ) : (
              <p className="px-4 py-6 text-center text-sm text-muted-foreground">No notifications yet.</p>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
