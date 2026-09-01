import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Users, MapPin, Package, Film } from 'lucide-react'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { fetchSeriesDetail } from '@/lib/api-content'
import type { Character } from '@/lib/types'

function AssetStrip({ title, icon: Icon, assets }: { title: string; icon: typeof Users; assets: Character[] }) {
  if (assets.length === 0) return null

  return (
    <div>
      <span className="mb-3 flex items-center gap-1.5 text-sm font-medium text-muted-foreground">
        <Icon className="h-4 w-4" />
        {title}
      </span>
      <div className="flex flex-wrap gap-4">
        {assets.map((asset) => (
          <div key={asset.id} className="flex flex-col items-center gap-1.5">
            <div className="h-16 w-16 overflow-hidden rounded-full border border-border bg-secondary">
              {asset.img_url ? (
                <img src={asset.img_url} alt={asset.name} className="h-full w-full object-cover" />
              ) : (
                <div className="flex h-full w-full items-center justify-center text-xs text-muted-foreground">?</div>
              )}
            </div>
            <span className="max-w-[5rem] truncate text-xs text-muted-foreground">{asset.name}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

export function SeriesDetailPage() {
  const { slug } = useParams<{ slug: string }>()

  const query = useQuery({
    queryKey: ['series', slug],
    queryFn: () => fetchSeriesDetail(slug!),
    enabled: !!slug,
  })

  if (query.isLoading || !query.data) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  const { data: series, characters, environments, props, episodes } = query.data

  return (
    <div className="flex flex-col gap-8">
      <div>
        <h1 className="font-display text-2xl font-medium">{series.title}</h1>
        {series.description && <p className="mt-1 text-sm text-muted-foreground">{series.description}</p>}
        <p className="mt-1 text-xs text-muted-foreground">
          {episodes.length} episode{episodes.length === 1 ? '' : 's'}
        </p>
      </div>

      <AssetStrip title="Characters" icon={Users} assets={characters} />
      <AssetStrip title="Environments" icon={MapPin} assets={environments} />
      <AssetStrip title="Props" icon={Package} assets={props} />

      <div>
        <h2 className="mb-3 flex items-center gap-1.5 text-sm font-medium text-muted-foreground">
          <Film className="h-4 w-4" />
          Episodes
        </h2>
        {episodes.length > 0 ? (
          <div className="flex flex-col gap-3">
            {episodes.map((episode) => (
              <Link key={episode.id} to={`/stories/${episode.slug}`}>
                <Card className="transition hover:border-primary">
                  <CardContent className="flex items-center justify-between gap-4 py-4">
                    <div className="min-w-0">
                      <p className="truncate text-sm">
                        {episode.episode_number ? `Episode ${episode.episode_number}: ` : ''}
                        {episode.title || 'Untitled'}
                      </p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        {new Date(episode.created_at).toLocaleDateString()}
                      </p>
                    </div>
                    <Badge variant={episode.prompt_generated ? 'success' : 'secondary'} className="shrink-0">
                      {episode.prompt_generated ? `${episode.image_prompts_count ?? 0} SCENES` : 'PROCESSING'}
                    </Badge>
                  </CardContent>
                </Card>
              </Link>
            ))}
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">No episodes yet.</p>
        )}
      </div>
    </div>
  )
}
