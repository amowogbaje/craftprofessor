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

  useEffect(() => {
    if (data) {
      setDailyImages(data.daily_image_limit)
      setDailyVideos(data.daily_video_limit)
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
    </div>
  )
}
