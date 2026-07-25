import { useState } from 'react'
import { Clapperboard, ImageIcon } from 'lucide-react'
import type { FeedItem } from '@/lib/types'
import { StatusChip } from './StatusChip'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'

export function FeedItemCard({ item, onClick }: { item: FeedItem; onClick: () => void }) {
  const [loaded, setLoaded] = useState(false)

  return (
    <button
      onClick={onClick}
      className="group relative flex aspect-[4/5] flex-col overflow-hidden rounded-md border border-border bg-card text-left transition-transform hover:-translate-y-0.5 hover:shadow-lg hover:shadow-black/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <div className="film-edge absolute inset-y-0 left-0 w-2 opacity-60 z-10" />
      <div className="film-edge absolute inset-y-0 right-0 w-2 opacity-60 z-10" />

      <div className="relative flex-1 overflow-hidden bg-secondary">
        {!loaded && <Skeleton className="absolute inset-0 rounded-none" />}

        {item.type === 'video' ? (
          <video
            src={item.url + '#t=0.5'}
            className={cn(
              'h-full w-full object-cover transition-all duration-500 group-hover:scale-105',
              loaded ? 'opacity-100' : 'opacity-0',
            )}
            muted
            playsInline
            preload="metadata"
            onLoadedData={() => setLoaded(true)}
          />
        ) : (
          <img
            src={item.url}
            alt={item.prompt ?? 'Generated scene'}
            loading="lazy"
            decoding="async"
            onLoad={() => setLoaded(true)}
            className={cn(
              'h-full w-full object-cover transition-all duration-500 group-hover:scale-105',
              loaded ? 'opacity-100' : 'opacity-0',
            )}
          />
        )}

        <div className="absolute right-2 top-2 flex h-6 w-6 items-center justify-center rounded-sm border border-border/80 bg-background/80 backdrop-blur-sm">
          {item.type === 'video' ? (
            <Clapperboard className="h-3.5 w-3.5 text-primary" />
          ) : (
            <ImageIcon className="h-3.5 w-3.5 text-muted-foreground" />
          )}
        </div>

        <div className="absolute bottom-2 left-2 right-2 flex justify-start">
          <StatusChip status={item.status} scheduledAt={item.scheduled_at} />
        </div>
      </div>

      {item.prompt && <p className="line-clamp-2 px-3 py-2 text-xs text-muted-foreground">{item.prompt}</p>}
    </button>
  )
}
