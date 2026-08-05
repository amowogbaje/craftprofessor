import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Plus, UserPlus, Users, LogOut, CalendarClock } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  fetchCause,
  fetchCauseBroadcasts,
  fetchCauseMedia,
  fetchCauseMembers,
  inviteToCause,
  joinCause,
  optOutOfCause,
  scheduleCauseBroadcast,
  uploadCauseMedia,
} from '@/lib/api-causes'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'
import { useAuth } from '@/context/AuthContext'
import type { CauseMedia, SocialProviderKey } from '@/lib/types'

const PROVIDERS: SocialProviderKey[] = ['pinterest', 'linkedin', 'twitter', 'youtube', 'instagram', 'facebook']

function UploadMediaDialog({ causeId }: { causeId: number }) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [title, setTitle] = useState('')
  const [details, setDetails] = useState('')
  const [url, setUrl] = useState('')
  const [linkUrl, setLinkUrl] = useState('')
  const [type, setType] = useState<'image' | 'video'>('image')

  const mutation = useMutation({
    mutationFn: () => uploadCauseMedia(causeId, { title, details: details || undefined, url, type, link_url: linkUrl || undefined }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['causes', causeId, 'media'] })
      toast({ title: 'Media added' })
      setOpen(false)
      setTitle('')
      setDetails('')
      setUrl('')
      setLinkUrl('')
    },
    onError: (err) => toast({ title: 'Could not add media', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button size="sm">
          <Plus className="h-4 w-4" />
          Add media
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add media to this cause</DialogTitle>
        </DialogHeader>
        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label>Title</Label>
            <Input value={title} onChange={(e) => setTitle(e.target.value)} />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Details</Label>
            <Textarea value={details} onChange={(e) => setDetails(e.target.value)} rows={3} />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Type</Label>
            <Select value={type} onValueChange={(v) => setType(v as 'image' | 'video')}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="image">Image</SelectItem>
                <SelectItem value="video">Video</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Media URL</Label>
            <Input value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://…" />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Link URL (optional)</Label>
            <Input value={linkUrl} onChange={(e) => setLinkUrl(e.target.value)} placeholder="Where a broadcast should link to" />
          </div>
        </div>
        <div className="flex justify-end pt-2">
          <Button disabled={!title.trim() || !url.trim() || mutation.isPending} onClick={() => mutation.mutate()}>
            {mutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Add media'}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function ScheduleBroadcastDialog({ causeId, media, memberUserId }: { causeId: number; media: CauseMedia; memberUserId: number }) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [provider, setProvider] = useState<SocialProviderKey>('pinterest')
  const [scheduledAt, setScheduledAt] = useState('')
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone

  const mutation = useMutation({
    mutationFn: () =>
      scheduleCauseBroadcast(causeId, media.id, { user_id: memberUserId, provider, scheduled_at: scheduledAt, timezone }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['causes', causeId, 'broadcasts'] })
      toast({ title: 'Broadcast scheduled', description: `Posting in your ${timezone} timezone.` })
      setOpen(false)
    },
    onError: (err) => toast({ title: 'Could not schedule', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button size="sm" variant="outline">
          <CalendarClock className="h-4 w-4" />
          Schedule
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Schedule "{media.title}"</DialogTitle>
        </DialogHeader>
        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label>Account</Label>
            <Select value={provider} onValueChange={(v) => setProvider(v as SocialProviderKey)}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {PROVIDERS.map((p) => (
                  <SelectItem key={p} value={p} className="capitalize">
                    {p}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>When ({timezone})</Label>
            <Input type="datetime-local" value={scheduledAt} onChange={(e) => setScheduledAt(e.target.value)} />
          </div>
        </div>
        <div className="flex justify-end pt-2">
          <Button disabled={!scheduledAt || mutation.isPending} onClick={() => mutation.mutate()}>
            {mutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Schedule'}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function InviteDialog({ causeId }: { causeId: number }) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [email, setEmail] = useState('')

  const mutation = useMutation({
    mutationFn: () => inviteToCause(causeId, email),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['causes', causeId, 'members'] })
      toast({ title: 'Invited', description: `${email} can now join this cause.` })
      setOpen(false)
      setEmail('')
    },
    onError: (err) => toast({ title: 'Could not invite', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button size="sm" variant="outline">
          <UserPlus className="h-4 w-4" />
          Invite
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Invite a member</DialogTitle>
        </DialogHeader>
        <div className="flex flex-col gap-1.5">
          <Label>Email</Label>
          <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="them@example.com" />
          <p className="text-xs text-muted-foreground">
            Once they join, their connected social accounts become eligible to broadcast this cause's media.
          </p>
        </div>
        <div className="flex justify-end pt-2">
          <Button disabled={!email.trim() || mutation.isPending} onClick={() => mutation.mutate()}>
            {mutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Send invite'}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

export function CauseDetailPage() {
  const { idOrSlug } = useParams<{ idOrSlug: string }>()
  const { user } = useAuth()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const causeQuery = useQuery({
    queryKey: ['causes', idOrSlug],
    queryFn: () => fetchCause(idOrSlug!),
    enabled: !!idOrSlug,
  })

  const cause = causeQuery.data
  const causeId = cause?.id

  const mediaQuery = useQuery({
    queryKey: ['causes', causeId, 'media'],
    queryFn: () => fetchCauseMedia(causeId!),
    enabled: !!causeId,
  })

  const membersQuery = useQuery({
    queryKey: ['causes', causeId, 'members'],
    queryFn: () => fetchCauseMembers(causeId!),
    enabled: !!causeId && cause?.owner_id === user?.id,
  })

  const broadcastsQuery = useQuery({
    queryKey: ['causes', causeId, 'broadcasts'],
    queryFn: () => fetchCauseBroadcasts(causeId!),
    enabled: !!causeId,
  })

  const joinMutation = useMutation({
    mutationFn: () => joinCause(causeId!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['causes', idOrSlug] })
      toast({ title: 'Joined cause', description: 'Your connected accounts can now be scheduled to broadcast its media.' })
    },
    onError: (err) => toast({ title: 'Could not join', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  const optOutMutation = useMutation({
    mutationFn: () => optOutOfCause(causeId!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['causes', idOrSlug] })
      toast({ title: 'Opted out' })
    },
    onError: (err) => toast({ title: 'Could not opt out', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  if (causeQuery.isLoading || !cause) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  const isOwner = cause.owner_id === user?.id
  const isJoined = cause.viewer_membership?.status === 'joined'

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="font-display text-2xl font-medium">{cause.title}</h1>
            {cause.status !== 'active' && (
              <Badge variant="secondary" className="capitalize">
                {cause.status.replace('_', ' ')}
              </Badge>
            )}
          </div>
          {cause.goal && <p className="text-sm text-muted-foreground">{cause.goal}</p>}
        </div>

        <div className="flex items-center gap-2">
          {isOwner && <InviteDialog causeId={cause.id} />}
          {!isOwner &&
            (isJoined ? (
              <Button size="sm" variant="outline" disabled={optOutMutation.isPending} onClick={() => optOutMutation.mutate()}>
                {optOutMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <LogOut className="h-4 w-4" />}
                Opt out
              </Button>
            ) : (
              <Button size="sm" disabled={joinMutation.isPending || cause.status !== 'active'} onClick={() => joinMutation.mutate()}>
                {joinMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Join cause'}
              </Button>
            ))}
        </div>
      </div>

      {cause.description && <p className="max-w-2xl text-sm text-muted-foreground">{cause.description}</p>}

      {cause.status === 'pending_payment' && isOwner && (
        <Card className="border-scheduled/40 bg-scheduled/5">
          <CardContent className="pt-6 text-sm text-muted-foreground">
            This cause is awaiting its $10 creation-fee payment before it goes live and can accept members.
          </CardContent>
        </Card>
      )}

      <div>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-sm font-medium text-muted-foreground">Media</h2>
          {isOwner && <UploadMediaDialog causeId={cause.id} />}
        </div>

        {mediaQuery.data?.length ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {mediaQuery.data.map((media) => (
              <Card key={media.id}>
                <CardHeader>
                  <CardTitle className="flex items-center justify-between text-base">
                    <span className="truncate">{media.title}</span>
                    <Badge variant="outline" className="text-xs capitalize">
                      {media.type}
                    </Badge>
                  </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                  {media.type === 'image' ? (
                    <img src={media.url} alt={media.title} className="aspect-video w-full rounded-md object-cover" />
                  ) : (
                    <video src={media.url} controls className="aspect-video w-full rounded-md" />
                  )}
                  {media.details && <p className="line-clamp-2 text-sm text-muted-foreground">{media.details}</p>}
                  {isJoined && user && <ScheduleBroadcastDialog causeId={cause.id} media={media} memberUserId={user.id} />}
                </CardContent>
              </Card>
            ))}
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">No media uploaded yet.</p>
        )}
      </div>

      <div>
        <h2 className="mb-3 flex items-center gap-1.5 text-sm font-medium text-muted-foreground">
          <Users className="h-4 w-4" />
          {isOwner ? 'Members' : 'Broadcast activity'}
        </h2>

        {isOwner && membersQuery.data && (
          <div className="flex flex-col gap-2">
            {membersQuery.data.map((member) => (
              <div key={member.id} className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm">
                <span>{member.user?.name ?? member.user?.email}</span>
                <Badge variant="secondary" className="text-xs capitalize">
                  {member.status.replace('_', ' ')}
                </Badge>
              </div>
            ))}
          </div>
        )}

        {broadcastsQuery.data && (
          <div className="mt-4 flex flex-col gap-2">
            {broadcastsQuery.data.map((broadcast) => (
              <div key={broadcast.id} className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm">
                <span className="capitalize">
                  {broadcast.provider} — {broadcast.media?.title}
                </span>
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                  {new Date(broadcast.scheduled_at).toLocaleString()}
                  <Badge
                    variant={broadcast.status === 'posted' ? 'success' : broadcast.status === 'failed' ? 'destructive' : 'scheduled'}
                    className="text-xs capitalize"
                  >
                    {broadcast.status}
                  </Badge>
                </div>
              </div>
            ))}
            {!broadcastsQuery.data.length && <p className="text-sm text-muted-foreground">Nothing scheduled yet.</p>}
          </div>
        )}
      </div>
    </div>
  )
}
