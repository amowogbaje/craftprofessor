import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Loader2, RotateCcw, Users, Clapperboard, MapPin, Package, ArrowRight, Pin } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { fetchStoryDetail, regenerateVideo, requestVideo, updateStoryPinterestSettings } from '@/lib/api-content'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'
import { StoryVideoAction } from '@/components/stories/StoryVideoAction'
import type { Character, CharacterScene, Story } from '@/lib/types'

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

function StoryPinterestSettings({
  story,
  boards,
  storySlug,
}: {
  story: Story
  boards: { id: number; name: string }[]
  storySlug: string
}) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['stories', storySlug] })

  // 'account-default' is a sentinel for "no override" (null on the
  // backend) — Radix Select can't bind an item value to an empty string.
  const [boardValue, setBoardValue] = useState(
    story.pinterest_board_id ? String(story.pinterest_board_id) : 'account-default',
  )
  const [dailyLimit, setDailyLimit] = useState(story.pinterest_daily_pin_limit?.toString() ?? '')

  const boardMutation = useMutation({
    mutationFn: (value: string) =>
      updateStoryPinterestSettings(story.id, {
        pinterest_board_id: value === 'account-default' ? null : Number(value),
      }),
    onSuccess: () => {
      invalidate()
      toast({ title: 'Pinterest board updated' })
    },
    onError: (err) => {
      setBoardValue(story.pinterest_board_id ? String(story.pinterest_board_id) : 'account-default')
      toast({ title: 'Could not save', description: apiErrorMessage(err), variant: 'destructive' })
    },
  })

  const limitMutation = useMutation({
    mutationFn: (value: string) =>
      updateStoryPinterestSettings(story.id, {
        pinterest_daily_pin_limit: value === '' ? null : Number(value),
      }),
    onSuccess: () => {
      invalidate()
      toast({ title: 'Daily pin limit updated' })
    },
    onError: (err) => {
      setDailyLimit(story.pinterest_daily_pin_limit?.toString() ?? '')
      toast({ title: 'Could not save', description: apiErrorMessage(err), variant: 'destructive' })
    },
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Pin className="h-4 w-4" /> Pinterest for this story
        </CardTitle>
        <CardDescription>
          Leave these unset to use whatever's chosen on your{' '}
          <Link to="/settings/pinterest-boards" className="underline underline-offset-2">
            Pinterest boards
          </Link>{' '}
          setup — these only override it for this story specifically.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="story-pinterest-board">Board</Label>
          <Select
            value={boardValue}
            disabled={boardMutation.isPending}
            onValueChange={(value) => {
              setBoardValue(value)
              boardMutation.mutate(value)
            }}
          >
            <SelectTrigger id="story-pinterest-board">
              <SelectValue placeholder="Account default" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="account-default">Account default</SelectItem>
              {boards.map((board) => (
                <SelectItem key={board.id} value={String(board.id)}>
                  {board.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="story-pinterest-limit">Pins per day</Label>
          <Input
            id="story-pinterest-limit"
            type="number"
            min={0}
            max={100}
            placeholder="Account default"
            disabled={limitMutation.isPending}
            value={dailyLimit}
            onChange={(e) => setDailyLimit(e.target.value)}
            onBlur={() => {
              if (dailyLimit !== (story.pinterest_daily_pin_limit?.toString() ?? '')) {
                limitMutation.mutate(dailyLimit)
              }
            }}
          />
        </div>
      </CardContent>
    </Card>
  )
}

function SceneVideoAction({ scene, storySlug }: { scene: CharacterScene; storySlug: string }) {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['stories', storySlug] })

  const generate = useMutation({
    mutationFn: () => requestVideo(scene.id),
    onSuccess: (data) => {
      toast({ title: data.data ? 'Video ready' : 'Video queued', description: data.message })
      invalidate()
    },
    onError: (err) => toast({ title: 'Could not start video', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  const regenerate = useMutation({
    mutationFn: () => regenerateVideo(scene.id),
    onSuccess: (data) => {
      toast({ title: data.data ? 'Regenerated' : 'Regenerating', description: data.message })
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

  const { data: story, characters, environments, props, characters_scope: charactersScope, characters_path: charactersPath, pinterest_boards: pinterestBoards } = query.data
  const scenes = story.image_prompts ?? []
  const isEpisode = charactersScope === 'series' && !!story.series

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

      <StoryPinterestSettings story={story} boards={pinterestBoards} storySlug={slug!} />

      {isEpisode ? (
        // Episodes share their character/environment/prop pool with every
        // other episode in the series — shown once on the series page
        // rather than repeated (and potentially stale) on each episode.
        <Link to={`/series/${story.series?.slug}`}>
          <Card className="transition hover:border-primary">
            <CardContent className="flex items-center justify-between gap-3 py-4">
              <div className="flex items-center gap-2 text-sm">
                <Users className="h-4 w-4 text-muted-foreground" />
                Characters, environments &amp; props are shared across every episode of{' '}
                <span className="font-medium">{story.series?.title}</span>
              </div>
              <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground" />
            </CardContent>
          </Card>
        </Link>
      ) : (
        <>
          <AssetStrip title="Characters" icon={Users} assets={characters} linkTo={charactersPath} />
          <AssetStrip title="Environments" icon={MapPin} assets={environments} />
          <AssetStrip title="Props" icon={Package} assets={props} />
        </>
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
                  <div className="flex items-center justify-between gap-2">
                    {typeof scene.scene_number === 'number' && (
                      <span className="shrink-0 text-[10px] font-medium text-muted-foreground">Scene {scene.scene_number}</span>
                    )}
                    <Badge variant={scene.video ? 'success' : 'secondary'} className="shrink-0 text-[10px]">
                      {scene.video ? 'VIDEO' : 'IMAGE'}
                    </Badge>
                  </div>
                  {scene.dialogue_lines && scene.dialogue_lines.length > 0 ? (
                    <div
                      className="space-y-0.5 text-xs text-muted-foreground"
                      title="Spoken dialogue for this scene — burned in as per-line captions on the assembled video"
                    >
                      {scene.dialogue_lines.map((line, i) => (
                        <p key={i} className="line-clamp-2">
                          <span className="font-medium text-foreground">{line.character_name}:</span> {line.text}
                        </p>
                      ))}
                    </div>
                  ) : (
                    scene.narration && (
                      <p className="line-clamp-3 text-xs text-muted-foreground" title="Also burned in as captions on the assembled video">
                        {scene.narration}
                      </p>
                    )
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
