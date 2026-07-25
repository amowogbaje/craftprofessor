import type { ContentStatus } from '@/lib/types'
import { cn } from '@/lib/utils'

function formatTimecode(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  return d
    .toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
    .toUpperCase()
    .replace(',', ' ·')
}

export function StatusChip({
  status,
  scheduledAt,
}: {
  status: ContentStatus
  scheduledAt?: string | null
}) {
  const config = {
    draft: { label: 'DRAFT', dot: 'bg-muted-foreground' },
    scheduled: { label: scheduledAt ? `SCHED · ${formatTimecode(scheduledAt)}` : 'SCHEDULED', dot: 'bg-scheduled' },
    published: { label: 'LIVE', dot: 'bg-success' },
  }[status]

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-sm border border-border/80 bg-background/80 px-2 py-1 font-mono text-[10px] font-medium tracking-widest text-foreground/90 backdrop-blur-sm',
      )}
    >
      <span className={cn('h-1.5 w-1.5 rounded-full', config.dot, status === 'published' && 'animate-pulse')} />
      {config.label}
    </span>
  )
}
