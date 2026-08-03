import { useQuery } from '@tanstack/react-query'
import { BarChart3, Link2, Send, CalendarDays, CalendarRange, Calendar } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { fetchLinkStats } from '@/lib/api-stats'
import type { PeriodSummary } from '@/lib/types'

function PeriodCard({
  label,
  icon,
  summary,
}: {
  label: string
  icon: React.ReactNode
  summary?: PeriodSummary
}) {
  const topClickPlatform = summary?.clicks_by_platform[0]
  const topPostPlatform = summary?.posts_by_platform[0]

  return (
    <Card>
      <CardContent className="flex flex-col gap-3 py-5">
        <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
          {icon}
          {label}
        </div>
        <div className="flex items-end gap-6">
          <div>
            <p className="font-mono text-2xl font-semibold">{summary?.clicks ?? 0}</p>
            <p className="text-xs text-muted-foreground">
              clicks{topClickPlatform ? ` · mostly ${topClickPlatform.social_media}` : ''}
            </p>
          </div>
          <div>
            <p className="font-mono text-2xl font-semibold">{summary?.posts ?? 0}</p>
            <p className="text-xs text-muted-foreground">
              posts{topPostPlatform ? ` · mostly ${topPostPlatform.platform}` : ''}
            </p>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function BarList({ rows, valueKey, labelKey }: { rows: Record<string, any>[]; valueKey: string; labelKey: string }) {
  const max = Math.max(1, ...rows.map((r) => r[valueKey]))

  if (rows.length === 0) {
    return <p className="text-sm text-muted-foreground">Nothing recorded yet.</p>
  }

  return (
    <div className="flex flex-col gap-3">
      {rows.map((row) => (
        <div key={row[labelKey]} className="flex flex-col gap-1">
          <div className="flex items-center justify-between text-sm">
            <span className="font-medium capitalize">{row[labelKey]}</span>
            <span className="font-mono text-muted-foreground">{row[valueKey]}</span>
          </div>
          <div className="h-2 w-full overflow-hidden rounded-full bg-secondary">
            <div className="h-full rounded-full bg-primary" style={{ width: `${Math.max(4, (row[valueKey] / max) * 100)}%` }} />
          </div>
        </div>
      ))}
    </div>
  )
}

export function StatsPage() {
  const { data, isLoading } = useQuery({ queryKey: ['link-stats'], queryFn: () => fetchLinkStats() })

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-2xl font-medium">Traffic & posting stats</h1>
        <p className="text-sm text-muted-foreground">
          How much traffic your posts are driving, and how much you're actually posting — daily, weekly, monthly, and across every platform.
        </p>
      </div>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <PeriodCard label="Today" icon={<CalendarDays className="h-4 w-4" />} summary={data?.periods.today} />
            <PeriodCard label="This week" icon={<CalendarRange className="h-4 w-4" />} summary={data?.periods.this_week} />
            <PeriodCard label="This month" icon={<Calendar className="h-4 w-4" />} summary={data?.periods.this_month} />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Card>
              <CardContent className="flex items-center gap-3 py-5">
                <div className="flex h-10 w-10 items-center justify-center rounded-md bg-primary/10 text-primary">
                  <BarChart3 className="h-5 w-5" />
                </div>
                <div>
                  <p className="font-mono text-2xl font-semibold">{data?.clicks.total ?? 0}</p>
                  <p className="text-xs text-muted-foreground">
                    Total clicks {data ? `(${data.range.from} → ${data.range.to})` : ''}
                  </p>
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="flex items-center gap-3 py-5">
                <div className="flex h-10 w-10 items-center justify-center rounded-md bg-primary/10 text-primary">
                  <Send className="h-5 w-5" />
                </div>
                <div>
                  <p className="font-mono text-2xl font-semibold">{data?.posts.total_posted ?? 0}</p>
                  <p className="text-xs text-muted-foreground">
                    Total posts {data && data.posts.total_failed > 0 ? `· ${data.posts.total_failed} failed` : ''}
                  </p>
                </div>
              </CardContent>
            </Card>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>Clicks by network</CardTitle>
                <CardDescription>Which platform is driving the most traffic back to your stories.</CardDescription>
              </CardHeader>
              <CardContent>
                <BarList rows={data?.clicks.by_social_media ?? []} valueKey="clicks" labelKey="social_media" />
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Posts by platform</CardTitle>
                <CardDescription>How much you're actually publishing to each connected platform.</CardDescription>
              </CardHeader>
              <CardContent>
                <BarList rows={data?.posts.by_platform ?? []} valueKey="posted" labelKey="platform" />
                {data?.posts.by_platform.some((p) => p.failed > 0) && (
                  <div className="mt-3 flex flex-wrap gap-1.5">
                    {data.posts.by_platform
                      .filter((p) => p.failed > 0)
                      .map((p) => (
                        <Badge key={p.platform} variant="destructive" className="text-xs">
                          {p.platform}: {p.failed} failed
                        </Badge>
                      ))}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Top performing stories</CardTitle>
              <CardDescription>The episodes generating the most click-throughs.</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
              {data?.clicks.top_stories.length === 0 ? (
                <p className="text-sm text-muted-foreground">No attributed clicks yet.</p>
              ) : (
                data?.clicks.top_stories.map((row) => (
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
