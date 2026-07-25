import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { fetchStories, submitStory } from '@/lib/api-content'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'
import { Input } from '@/components/ui/input'


export function StoriesPage() {
  const [text, setText] = useState('')
  const [storyLink, setStoryLink] = useState('')
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({ queryKey: ['stories'], queryFn: fetchStories })

  const mutation = useMutation({
    mutationFn: () => submitStory(text, storyLink),
    onSuccess: () => {
      setText('')
      setStoryLink('')
      queryClient.invalidateQueries({ queryKey: ['stories'] })
      toast({ title: 'Story submitted', description: 'Prompts will start generating shortly.' })
    },
    onError: (err) => toast({ title: 'Could not submit story', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-2xl font-medium">Stories</h1>
        <p className="text-sm text-muted-foreground">Paste a story and Storyframe turns it into scenes.</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>New story</CardTitle>
          <CardDescription>At least a few paragraphs works best — more detail, better scenes.</CardDescription>
        </CardHeader>
        <CardContent>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              mutation.mutate()
            }}
            className="flex flex-col gap-3"
          >
            <Input
              type="url"
              value={storyLink}
              onChange={(e) => setStoryLink(e.target.value)}
              placeholder="https://example.com/original-story (optional)"
            />
            
            <Textarea
              value={text}
              onChange={(e) => setText(e.target.value)}
              placeholder="Once upon a time…"
              minLength={50}
              required
              rows={8}
            />
            <Button type="submit" disabled={mutation.isPending} className="self-start">
              {mutation.isPending ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" /> Submitting…
                </>
              ) : (
                'Submit story'
              )}
            </Button>
          </form>
        </CardContent>
      </Card>

      <div className="flex flex-col gap-3">
        <h2 className="font-display text-lg font-medium">Your stories</h2>
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : data?.data.length === 0 ? (
          <p className="text-sm text-muted-foreground">No stories yet — submit one above.</p>
        ) : (
          data?.data.map((story) => (
            <Card key={story.id}>
              <CardContent className="flex items-center justify-between gap-4 py-4">
                <div className="min-w-0">
                  <p className="truncate text-sm">
                    {story.user_supplied_text?.slice(0, 140) ?? story.story_link ?? 'Untitled story'}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {story.series ? `Series: ${story.series.title}` : 'Standalone'} ·{' '}
                    {new Date(story.created_at).toLocaleDateString()}
                  </p>
                </div>
                <Badge variant={story.prompt_generated ? 'success' : 'secondary'}>
                  {story.prompt_generated ? `${story.image_prompts_count ?? 0} PROMPTS` : 'PROCESSING'}
                </Badge>
              </CardContent>
            </Card>
          ))
        )}
      </div>
    </div>
  )
}
