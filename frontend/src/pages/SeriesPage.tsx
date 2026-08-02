import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Layers, Sparkles } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { fetchSeries, importStoryVerseSeries } from '@/lib/api-content'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'

export function SeriesPage() {
  const [url, setUrl] = useState('')
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({ queryKey: ['series'], queryFn: () => fetchSeries() })

  const mutation = useMutation({
    mutationFn: () => importStoryVerseSeries(url.trim()),
    onSuccess: (result) => {
      setUrl('')
      queryClient.invalidateQueries({ queryKey: ['series'] })
      toast({
        title: 'Series imported',
        description: `"${result.series.title}" — ${result.series.episodes.length} episode(s) pulled in.`,
      })
    },
    onError: (err) => toast({ title: 'Could not import series', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-2xl font-medium">Series</h1>
        <p className="text-sm text-muted-foreground">
          Paste any episode URL from StoryVerse — the whole series comes in with it.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Import from StoryVerse</CardTitle>
          <CardDescription>
            Paste a story URL, e.g. https://storyverse.amowogbaje.com/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil.
            Every episode in that series is imported (or refreshed) in one go.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              mutation.mutate()
            }}
            className="flex flex-col gap-3 sm:flex-row sm:items-end"
          >
            <div className="flex flex-1 flex-col gap-1.5">
              <Label htmlFor="storyverse-url">StoryVerse story URL or slug</Label>
              <Input
                id="storyverse-url"
                value={url}
                placeholder="https://storyverse.amowogbaje.com/stories/…"
                onChange={(e) => setUrl(e.target.value)}
              />
            </div>

            <Button type="submit" disabled={mutation.isPending || !url.trim()}>
              {mutation.isPending ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" /> Importing…
                </>
              ) : (
                <>
                  <Sparkles className="h-4 w-4" /> Import series
                </>
              )}
            </Button>
          </form>
        </CardContent>
      </Card>

      <div className="flex flex-col gap-3">
        <h2 className="font-display text-lg font-medium">Your series</h2>
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : data?.data.length === 0 ? (
          <p className="text-sm text-muted-foreground">No series yet.</p>
        ) : (
          data?.data.map((series) => (
            <Card key={series.id}>
              <CardContent className="flex items-center gap-3 py-4">
                {series.cover_image_url ? (
                  <img
                    src={series.cover_image_url}
                    alt=""
                    className="h-10 w-10 shrink-0 rounded-md object-cover"
                  />
                ) : (
                  <Layers className="h-4 w-4 shrink-0 text-muted-foreground" />
                )}
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{series.title}</p>
                  <p className="text-xs text-muted-foreground">
                    {series.stories_count ?? 0} episode{series.stories_count === 1 ? '' : 's'}
                    {series.source === 'storyverse' ? ' · StoryVerse' : ''}
                  </p>
                </div>
              </CardContent>
            </Card>
          ))
        )}
      </div>
    </div>
  )
}
