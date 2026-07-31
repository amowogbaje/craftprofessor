import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'

interface LoadMoreButtonProps {
  onClick: () => void
  isLoading: boolean
  hasMore: boolean
  totalShown: number
  total: number
}

export function LoadMoreButton({ onClick, isLoading, hasMore, totalShown, total }: LoadMoreButtonProps) {
  if (!hasMore) return null

  return (
    <div className="flex flex-col items-center gap-2 pt-2">
      <Button variant="outline" onClick={onClick} disabled={isLoading}>
        {isLoading ? (
          <>
            <Loader2 className="h-4 w-4 animate-spin" /> Loading…
          </>
        ) : (
          'Load more'
        )}
      </Button>
      <p className="text-xs text-muted-foreground">
        Showing {totalShown} of {total}
      </p>
    </div>
  )
}
