import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Clapperboard, Loader2, RotateCcw, ExternalLink, Send, CheckCircle2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { fetchStoryVideo, publishStoryVideoToPinterest, requestStoryVideo } from '@/lib/api-content'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'
import type { Story } from '@/lib/types'

/**
 * Per-story "generate the full scene-by-scene video with narration" action.
 * Polls while a video is 'processing' (ffmpeg assembly can take a while),
 * stops polling once it lands on 'ready' or 'failed'.
 */
export function StoryVideoAction({ story }: { story: Story }) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  // Once true, stays true — flips on if the story already has a video row,
  // or the moment "Generate video" is clicked. Kept separate from the
  // query's own enabled/disabled state so polling doesn't get shut off by
  // React Query treating `enabled: false` as "never auto-refetch".
  const [hasRequested, setHasRequested] = useState(!!story.video)

  const query = useQuery({
    queryKey: ['story-video', story.id],
    queryFn: () => fetchStoryVideo(story.id),
    initialData: story.video ?? undefined,
    enabled: hasRequested,
    refetchInterval: (q) => (q.state.data?.status === 'processing' || q.state.data?.status === 'pending' ? 5000 : false),
  })

  const mutation = useMutation({
    mutationFn: () => requestStoryVideo(story.id),
    onSuccess: ({ data }) => {
      setHasRequested(true)
      queryClient.setQueryData(['story-video', story.id], data)
      queryClient.invalidateQueries({ queryKey: ['stories'] })
      toast({ title: 'Video queued', description: 'Stitching scenes together — this can take a few minutes.' })
    },
    onError: (err) => toast({ title: 'Could not start video', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  const publishMutation = useMutation({
    mutationFn: () => publishStoryVideoToPinterest(story.id),
    onSuccess: ({ data }) => {
      queryClient.setQueryData(['story-video', story.id], data)
      toast({ title: 'Posted to Pinterest', description: 'The narrated video with captions is live.' })
    },
    onError: (err) => toast({ title: 'Could not publish', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  if (!story.prompt_generated || (story.image_prompts_count ?? 0) === 0) {
    return null
  }

  const video = query.data

  if (!video || video.status === 'failed') {
    return (
      <div className="flex items-center gap-2">
        {video?.status === 'failed' && (
          <Badge variant="destructive" title={video.last_generation_error ?? undefined}>
            FAILED
          </Badge>
        )}
        <Button
          size="sm"
          variant="outline"
          disabled={mutation.isPending}
          onClick={() => mutation.mutate()}
          className="gap-1.5"
        >
          {mutation.isPending ? (
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
          ) : video?.status === 'failed' ? (
            <RotateCcw className="h-3.5 w-3.5" />
          ) : (
            <Clapperboard className="h-3.5 w-3.5" />
          )}
          {video?.status === 'failed' ? 'Retry video' : 'Generate video'}
        </Button>
      </div>
    )
  }

  if (video.status === 'pending' || video.status === 'processing') {
    return (
      <Badge variant="scheduled" className="gap-1.5">
        <Loader2 className="h-3 w-3 animate-spin" />
        {video.status === 'pending' ? 'Queued' : 'Assembling…'}
      </Badge>
    )
  }

  return (
    <div className="flex items-center gap-2">
      <Badge variant="success">
        {video.duration_seconds ? `${Math.round(video.duration_seconds)}s VIDEO` : 'VIDEO READY'}
      </Badge>
      <a
        href={video.video_url ?? '#'}
        target="_blank"
        rel="noreferrer"
        className="inline-flex items-center gap-1 text-xs text-primary underline-offset-4 hover:underline"
      >
        <ExternalLink className="h-3 w-3" />
        Watch
      </a>
      {video.posted_to_pinterest ? (
        <Badge variant="secondary" className="gap-1" title={video.pinterest_pin_id ?? undefined}>
          <CheckCircle2 className="h-3 w-3" />
          On Pinterest
        </Badge>
      ) : (
        <Button
          size="sm"
          variant="outline"
          disabled={publishMutation.isPending}
          onClick={() => publishMutation.mutate()}
          className="gap-1.5"
          title="Posts this narrated + captioned video — never a raw scene clip — as a Pinterest video pin"
        >
          {publishMutation.isPending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />}
          Publish to Pinterest
        </Button>
      )}
      <Button
        size="sm"
        variant="outline"
        disabled={mutation.isPending}
        onClick={() => mutation.mutate()}
        className="gap-1.5"
        title="Re-assemble (e.g. after regenerating a scene)"
      >
        {mutation.isPending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RotateCcw className="h-3.5 w-3.5" />}
        Regenerate
      </Button>
    </div>
  )
}
