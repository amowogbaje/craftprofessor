import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Users } from 'lucide-react'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { fetchStoryDetail } from '@/lib/api-content'
import { StoryVideoAction } from '@/components/stories/StoryVideoAction'

export function StoryDetailPage() {
  const { slug } = useParams<{ slug: string }>()

  const query = useQuery({
    queryKey: ['stories', slug],
    queryFn: () => fetchStoryDetail(slug!),
    enabled: !!slug,
  })

  if (query.isLoading || !query.data) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  const { data: story, characters, characters_path: charactersPath } = query.data
  const scenes = story.image_prompts ?? []

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="font-display text-2xl font-medium">{story.title || 'Untitled story'}</h1>
          <p className="mt-1 text-xs text-muted-foreground">
            {story.series ? `Episode of ${story.series.title}` : 'Standalone story'}
          </p>
        </div>
        <StoryVideoAction story={story} />
      </div>

      {characters.length > 0 && (
        <div>
          <Link to={charactersPath} className="mb-3 flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground">
            <Users className="h-4 w-4" />
            Characters
          </Link>
          <div className="flex flex-wrap gap-4">
            {characters.map((character) => (
              <Link
                key={character.id}
                to={`${charactersPath}?character=${character.id}`}
                className="group flex flex-col items-center gap-1.5"
              >
                <div className="h-16 w-16 overflow-hidden rounded-full border border-border bg-secondary transition group-hover:border-primary">
                  {character.img_url ? (
                    <img src={character.img_url} alt={character.name} className="h-full w-full object-cover" />
                  ) : (
                    <div className="flex h-full w-full items-center justify-center text-xs text-muted-foreground">?</div>
                  )}
                </div>
                <span className="max-w-[5rem] truncate text-xs text-muted-foreground group-hover:text-foreground">
                  {character.name}
                </span>
              </Link>
            ))}
          </div>
        </div>
      )}

      <div>
        <h2 className="mb-3 text-sm font-medium text-muted-foreground">Scenes ({scenes.length})</h2>
        {scenes.length > 0 ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {scenes.map((scene) => (
              <Card key={scene.id}>
                <CardContent className="flex flex-col gap-2 p-3">
                  <div className="overflow-hidden rounded-md border border-border bg-secondary">
                    {scene.video?.video_url ? (
                      <video src={scene.video.video_url} controls className="aspect-[9/16] w-full object-cover" />
                    ) : scene.image_generated_url ? (
                      <img src={scene.image_generated_url} alt={scene.prompt} className="aspect-[9/16] w-full object-cover" />
                    ) : (
                      <div className="flex aspect-[9/16] w-full items-center justify-center text-xs text-muted-foreground">
                        Generating…
                      </div>
                    )}
                  </div>
                  <div className="flex items-center justify-between">
                    {typeof scene.scene_number === 'number' && (
                      <span className="text-[10px] font-medium text-muted-foreground">Scene {scene.scene_number}</span>
                    )}
                    <Badge variant={scene.video ? 'success' : 'secondary'} className="text-[10px]">
                      {scene.video ? 'VIDEO' : 'IMAGE'}
                    </Badge>
                  </div>
                  {scene.narration && <p className="line-clamp-2 text-xs text-muted-foreground">{scene.narration}</p>}
                </CardContent>
              </Card>
            ))}
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">Scenes are still being generated for this story.</p>
        )}
      </div>
    </div>
  )
}
