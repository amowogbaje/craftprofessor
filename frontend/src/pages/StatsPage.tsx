import { useQuery } from '@tanstack/react-query'
import { BarChart3, Link2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { fetchLinkStats } from '@/lib/api-stats'

export function StatsPage() {
  const { data, isLoading } = useQuery({ queryKey: ['link-stats'], queryFn: () => fetchLinkStats() })

  const maxClicks = Math.max(1, ...(data?.by_social_media.map((s) => s.clicks) ?? [1]))

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-2xl font-medium">Traffic stats</h1>
        <p className="text-sm text-muted-foreground">
          How much traffic your social posts are driving back to your sites, and which network is working hardest.
        </p>
      </div>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (
        <>
          <Card>
            <CardContent className="flex items-center gap-3 py-5">
              <div className="flex h-10 w-10 items-center justify-center rounded-md bg-primary/10 text-primary">
                <BarChart3 className="h-5 w-5" />
              </div>
              <div>
                <p className="font-mono text-2xl font-semibold">{data?.total_clicks ?? 0}</p>
                <p className="text-xs text-muted-foreground">
                  Total clicks {data ? `(${data.range.from} → ${data.range.to})` : ''}
                </p>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>By network</CardTitle>
              <CardDescription>Which social platform is driving the most clicks back to your stories.</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
              {data?.by_social_media.length === 0 ? (
                <p className="text-sm text-muted-foreground">No clicks recorded yet.</p>
              ) : (
                data?.by_social_media.map((row) => (
                  <div key={row.social_media} className="flex flex-col gap-1">
                    <div className="flex items-center justify-between text-sm">
                      <span className="font-medium">{row.social_media}</span>
                      <span className="font-mono text-muted-foreground">{row.clicks}</span>
                    </div>
                    <div className="h-2 w-full overflow-hidden rounded-full bg-secondary">
                      <div
                        className="h-full rounded-full bg-primary"
                        style={{ width: `${Math.max(4, (row.clicks / maxClicks) * 100)}%` }}
                      />
                    </div>
                  </div>
                ))
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Top performing stories</CardTitle>
              <CardDescription>The episodes generating the most click-throughs.</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
              {data?.top_stories.length === 0 ? (
                <p className="text-sm text-muted-foreground">No attributed clicks yet.</p>
              ) : (
                data?.top_stories.map((row) => (
                  <div key={row.story_id} className="flex items-center gap-3 rounded-md border border-border px-3 py-2.5">
                    <Link2 className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">
                        {row.story?.title ?? row.story?.story_link ?? `Story #${row.story_id}`}
                      </p>
                      {row.story?.episode_number && (
                        <p className="text-xs text-muted-foreground">Episode {row.story.episode_number}</p>
                      )}
                    </div>
                    <Badge variant="secondary" className="font-mono">
                      {row.clicks} clicks
                    </Badge>
                  </div>
                ))
              )}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}
