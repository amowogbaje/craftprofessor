import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, RotateCcw, Users, Clapperboard, MapPin, Package } from 'lucide-react'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { fetchStoryDetail, regenerateVideo, requestVideo } from '@/lib/api-content'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'
import { StoryVideoAction } from '@/components/stories/StoryVideoAction'
import type { Character, CharacterScene } from '@/lib/types'

function AssetStrip({ title, icon: Icon, assets, linkTo }: {
  title: string
  icon: typeof Users
  assets: Character[]
  linkTo?: string
}) {
  if (assets.length === 0) return null

  const heading = (
    <span className="mb-3 flex items-center gap-1.5 text-sm font-medium text-muted-foreground">
      <Icon className="h-4 w-4" />
      {title}
    </span>
  )

  return (
    <div>
      {linkTo ? (
        <Link to={linkTo} className="hover:text-foreground">{heading}</Link>
      ) : (
        heading
      )}
      <div className="flex flex-wrap gap-4">
        {assets.map((asset) => {
          const avatar = (
            <div className="h-16 w-16 overflow-hidden rounded-full border border-border bg-secondary transition group-hover:border-primary">
              {asset.img_url ? (
                <img src={asset.img_url} alt={asset.name} className="h-full w-full object-cover" />
              ) : (
                <div className="flex h-full w-full items-center justify-center text-xs text-muted-foreground">?</div>
              )}
            </div>
          )

          return linkTo ? (
            <Link key={asset.id} to={`${linkTo}?character=${asset.id}`} className="group flex flex-col items-center gap-1.5">
              {avatar}
              <span className="max-w-[5rem] truncate text-xs text-muted-foreground group-hover:text-foreground">{asset.name}</span>
            </Link>
          ) : (
            <div key={asset.id} className="flex flex-col items-center gap-1.5">
              {avatar}
              <span className="max-w-[5rem] truncate text-xs text-muted-foreground">{asset.name}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

function SceneVideoAction({ scene, storySlug }: { scene: CharacterScene; storySlug: string }) {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['stories', storySlug] })

  const generate = useMutation({
    mutationFn: () => requestVideo(scene.id),
    onSuccess: () => {
      toast({ title: 'Video queued', description: `Scene ${scene.scene_number ?? scene.id} — this can take a few minutes.` })
      invalidate()
    },
    onError: (err) => toast({ title: 'Could not start video', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  const regenerate = useMutation({
    mutationFn: () => regenerateVideo(scene.id),
    onSuccess: () => {
      toast({ title: 'Regenerating', description: `Previous clip cleared for scene ${scene.scene_number ?? scene.id}.` })
      invalidate()
    },
    onError: (err) => toast({ title: 'Could not regenerate', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  if (!scene.image_generated_url) return null

  const pending = generate.isPending || regenerate.isPending

  return (
    <Button
      size="sm"
      variant="outline"
      disabled={pending}
      onClick={() => (scene.video ? regenerate.mutate() : generate.mutate())}
      className="h-7 gap-1 px-2 text-[10px]"
    >
      {pending ? (
        <Loader2 className="h-3 w-3 animate-spin" />
      ) : scene.video ? (
        <RotateCcw className="h-3 w-3" />
      ) : (
        <Clapperboard className="h-3 w-3" />
      )}
      {scene.video ? 'Regenerate' : 'Generate video'}
    </Button>
  )
}

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

  const { data: story, characters, environments, props, characters_path: charactersPath } = query.data
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

      <AssetStrip title="Characters" icon={Users} assets={characters} linkTo={charactersPath} />
      <AssetStrip title="Environments" icon={MapPin} assets={environments} />
      <AssetStrip title="Props" icon={Package} assets={props} />

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
                  <div className="flex items-center justify-between gap-2">
                    {typeof scene.scene_number === 'number' && (
                      <span className="shrink-0 text-[10px] font-medium text-muted-foreground">Scene {scene.scene_number}</span>
                    )}
                    <Badge variant={scene.video ? 'success' : 'secondary'} className="shrink-0 text-[10px]">
                      {scene.video ? 'VIDEO' : 'IMAGE'}
                    </Badge>
                  </div>
                  {scene.narration && (
                    <p className="line-clamp-3 text-xs text-muted-foreground" title="Also burned in as captions on the assembled video">
                      {scene.narration}
                    </p>
                  )}
                  {slug && <SceneVideoAction scene={scene} storySlug={slug} />}
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
