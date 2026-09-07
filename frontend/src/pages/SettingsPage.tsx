import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { fetchPublishSettings, updatePublishSettings } from '@/lib/api-wallet'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'

export function SettingsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['publish-settings'], queryFn: fetchPublishSettings })

  const [dailyImages, setDailyImages] = useState(3)
  const [dailyVideos, setDailyVideos] = useState(1)
  const [autoGenerateVideos, setAutoGenerateVideos] = useState(false)

  useEffect(() => {
    if (data) {
      setDailyImages(data.daily_image_limit)
      setDailyVideos(data.daily_video_limit)
      setAutoGenerateVideos(data.auto_generate_scene_videos)
    }
  }, [data])

  const mutation = useMutation({
    mutationFn: () => updatePublishSettings({ daily_image_limit: dailyImages, daily_video_limit: dailyVideos }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['publish-settings'] })
      toast({ title: 'Publish limits saved' })
    },
    onError: (err) => toast({ title: 'Could not save', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  const autoGenerateMutation = useMutation({
    mutationFn: (value: boolean) => updatePublishSettings({ auto_generate_scene_videos: value }),
    onSuccess: (_data, value) => {
      queryClient.invalidateQueries({ queryKey: ['publish-settings'] })
      toast({ title: value ? 'Automatic video generation enabled' : 'Automatic video generation disabled' })
    },
    onError: (err, _value, _ctx) => {
      // Revert the optimistic checkbox flip if the save failed.
      setAutoGenerateVideos((prev) => !prev)
      toast({ title: 'Could not save', description: apiErrorMessage(err), variant: 'destructive' })
    },
  })

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-2xl font-medium">Publish limits</h1>
        <p className="text-sm text-muted-foreground">
          Cap how much generates per day, independent of your coin balance.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Daily limits</CardTitle>
          <CardDescription>Generation pauses for the day once a limit is hit, and resumes tomorrow.</CardDescription>
        </CardHeader>
        <CardContent>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              mutation.mutate()
            }}
            className="flex flex-col gap-4"
          >
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="daily-images">Images per day</Label>
                <Input
                  id="daily-images"
                  type="number"
                  min={0}
                  max={100}
                  disabled={isLoading}
                  value={dailyImages}
                  onChange={(e) => setDailyImages(Number(e.target.value))}
                />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="daily-videos">Videos per day</Label>
                <Input
                  id="daily-videos"
                  type="number"
                  min={0}
                  max={50}
                  disabled={isLoading}
                  value={dailyVideos}
                  onChange={(e) => setDailyVideos(Number(e.target.value))}
                />
              </div>
            </div>
            <Button type="submit" disabled={mutation.isPending} className="self-start">
              {mutation.isPending ? 'Saving…' : 'Save limits'}
            </Button>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Scene video generation</CardTitle>
          <CardDescription>
            Choose whether per-scene AI video clips are generated automatically as scenes become ready, or only
            when you trigger it yourself from a story's page. Either way, generation still stops once you hit the
            "Videos per day" limit above.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <label className="flex cursor-pointer items-start gap-3">
            <input
              type="checkbox"
              className="mt-0.5 h-4 w-4 shrink-0 rounded border-border text-primary focus:ring-primary"
              disabled={isLoading || autoGenerateMutation.isPending}
              checked={autoGenerateVideos}
              onChange={(e) => {
                const value = e.target.checked
                setAutoGenerateVideos(value)
                autoGenerateMutation.mutate(value)
              }}
            />
            <span className="flex flex-col">
              <span className="text-sm font-medium">Automatically generate scene videos</span>
              <span className="text-xs text-muted-foreground">
                {autoGenerateVideos
                  ? "On — scenes get a video clip automatically once they're ready, no click needed."
                  : 'Off — click "Generate video" on a scene yourself when you want one.'}
              </span>
            </span>
          </label>
        </CardContent>
      </Card>
    </div>
  )
}
