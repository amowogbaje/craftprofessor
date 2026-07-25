import { Search } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { FeedSort, FeedType } from '@/lib/api-feed'
import type { ContentStatus } from '@/lib/types'

interface Props {
  type: FeedType
  status: 'all' | ContentStatus
  sort: FeedSort
  search: string
  onTypeChange: (v: FeedType) => void
  onStatusChange: (v: 'all' | ContentStatus) => void
  onSortChange: (v: FeedSort) => void
  onSearchChange: (v: string) => void
}

export function FeedToolbar({
  type,
  status,
  sort,
  search,
  onTypeChange,
  onStatusChange,
  onSortChange,
  onSearchChange,
}: Props) {
  return (
    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <Tabs value={type} onValueChange={(v) => onTypeChange(v as FeedType)}>
        <TabsList>
          <TabsTrigger value="all">All</TabsTrigger>
          <TabsTrigger value="image">Images</TabsTrigger>
          <TabsTrigger value="video">Videos</TabsTrigger>
        </TabsList>
      </Tabs>

      <div className="flex flex-1 flex-wrap items-center gap-2 md:justify-end">
        <div className="relative w-full max-w-xs">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={search}
            onChange={(e) => onSearchChange(e.target.value)}
            placeholder="Search prompts…"
            className="pl-8"
          />
        </div>

        <Select value={status} onValueChange={(v) => onStatusChange(v as 'all' | ContentStatus)}>
          <SelectTrigger className="w-36">
            <SelectValue placeholder="Status" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="draft">Draft</SelectItem>
            <SelectItem value="scheduled">Scheduled</SelectItem>
            <SelectItem value="published">Published</SelectItem>
          </SelectContent>
        </Select>

        <Select value={sort} onValueChange={(v) => onSortChange(v as FeedSort)}>
          <SelectTrigger className="w-40">
            <SelectValue placeholder="Sort" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="newest">Newest first</SelectItem>
            <SelectItem value="oldest">Oldest first</SelectItem>
            <SelectItem value="scheduled_at">Upcoming schedule</SelectItem>
          </SelectContent>
        </Select>
      </div>
    </div>
  )
}
