import { Film, Loader2 } from 'lucide-react'
import type { FeedItem } from '@/lib/types'
import { FeedItemCard } from './FeedItemCard'
import { Skeleton } from '@/components/ui/skeleton'
import { useInView } from '@/hooks/useInView'

interface Props {
  items: FeedItem[]
  isLoading: boolean
  isFetchingNextPage: boolean
  hasNextPage: boolean
  onLoadMore: () => void
  onSelect: (item: FeedItem) => void
}

export function FeedGrid({ items, isLoading, isFetchingNextPage, hasNextPage, onLoadMore, onSelect }: Props) {
  const sentinelRef = useInView(onLoadMore, hasNextPage && !isLoading)

  if (isLoading) {
    return (
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        {Array.from({ length: 10 }).map((_, i) => (
          <Skeleton key={i} className="aspect-[4/5] rounded-md" />
        ))}
      </div>
    )
  }

  if (items.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border py-24 text-center">
        <Film className="h-8 w-8 text-muted-foreground" />
        <div>
          <p className="font-display text-lg">Nothing here yet</p>
          <p className="text-sm text-muted-foreground">
            Adjust your filters, or submit a story to start generating scenes.
          </p>
        </div>
      </div>
    )
  }

  return (
    <div>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        {items.map((item) => (
          <FeedItemCard key={`${item.type}-${item.id}`} item={item} onClick={() => onSelect(item)} />
        ))}
      </div>

      <div ref={sentinelRef} className="flex h-16 items-center justify-center">
        {isFetchingNextPage && <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />}
        {!hasNextPage && items.length > 0 && (
          <span className="font-mono text-xs text-muted-foreground">— END OF REEL —</span>
        )}
      </div>
    </div>
  )
}
