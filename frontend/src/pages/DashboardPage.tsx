import { useMemo, useState } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import { fetchFeedPage, type FeedSort, type FeedType } from '@/lib/api-feed'
import type { ContentStatus, FeedItem } from '@/lib/types'
import { FeedToolbar } from '@/components/feed/FeedToolbar'
import { FeedGrid } from '@/components/feed/FeedGrid'
import { FeedItemDialog } from '@/components/feed/FeedItemDialog'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'

export function DashboardPage() {
  const [type, setType] = useState<FeedType>('all')
  const [status, setStatus] = useState<'all' | ContentStatus>('all')
  const [sort, setSort] = useState<FeedSort>('newest')
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<FeedItem | null>(null)

  const debouncedSearch = useDebouncedValue(search)

  const query = useInfiniteQuery({
    queryKey: ['feed', type, status, sort, debouncedSearch],
    queryFn: ({ pageParam }) =>
      fetchFeedPage({ type, status, sort, search: debouncedSearch, cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.next_cursor,
  })

  const items = useMemo(() => query.data?.pages.flatMap((p) => p.data) ?? [], [query.data])

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-2xl font-medium">The reel</h1>
        <p className="text-sm text-muted-foreground">Everything you've generated, in one contact sheet.</p>
      </div>

      <FeedToolbar
        type={type}
        status={status}
        sort={sort}
        search={search}
        onTypeChange={setType}
        onStatusChange={setStatus}
        onSortChange={setSort}
        onSearchChange={setSearch}
      />

      <FeedGrid
        items={items}
        isLoading={query.isLoading}
        isFetchingNextPage={query.isFetchingNextPage}
        hasNextPage={!!query.hasNextPage}
        onLoadMore={() => query.fetchNextPage()}
        onSelect={setSelected}
      />

      <FeedItemDialog item={selected} onClose={() => setSelected(null)} />
    </div>
  )
}
