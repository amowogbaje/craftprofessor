import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Plus, Search, Users } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { createCause, fetchMyCauses, searchCauses } from '@/lib/api-causes'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'
import type { Cause } from '@/lib/types'

function CauseCard({ cause }: { cause: Cause }) {
  return (
    <Link to={`/causes/${cause.slug}`}>
      <Card className="h-full transition-colors hover:bg-secondary/40">
        <CardHeader>
          <div className="flex items-start justify-between gap-2">
            <CardTitle className="text-base">{cause.title}</CardTitle>
            {cause.status !== 'active' && (
              <Badge variant="secondary" className="shrink-0 text-xs capitalize">
                {cause.status.replace('_', ' ')}
              </Badge>
            )}
          </div>
          {cause.goal && <CardDescription>{cause.goal}</CardDescription>}
        </CardHeader>
        <CardContent>
          {cause.description && <p className="line-clamp-2 text-sm text-muted-foreground">{cause.description}</p>}
          <div className="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground">
            <Users className="h-3.5 w-3.5" />
            {cause.joined_members_count ?? 0} joined
          </div>
        </CardContent>
      </Card>
    </Link>
  )
}

function CreateCauseDialog() {
  const { toast } = useToast()
  const [open, setOpen] = useState(false)
  const [title, setTitle] = useState('')
  const [goal, setGoal] = useState('')
  const [description, setDescription] = useState('')

  const createMutation = useMutation({
    mutationFn: () => createCause({ title, goal: goal || undefined, description: description || undefined }),
    onSuccess: ({ payment_link }) => {
      toast({ title: 'Cause created', description: 'Redirecting you to pay the $10 creation fee…' })
      window.location.href = payment_link
    },
    onError: (err) => toast({ title: 'Could not create cause', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button>
          <Plus className="h-4 w-4" />
          New cause
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Create a cause</DialogTitle>
        </DialogHeader>
        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="cause-title">Title</Label>
            <Input id="cause-title" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Clean water for Ile-Ife" />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="cause-goal">Goal</Label>
            <Input id="cause-goal" value={goal} onChange={(e) => setGoal(e.target.value)} placeholder="Short, searchable summary" />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="cause-description">Description</Label>
            <Textarea id="cause-description" value={description} onChange={(e) => setDescription(e.target.value)} rows={4} />
          </div>
          <p className="text-xs text-muted-foreground">
            Creating a cause costs a one-time $10 fee. You'll be redirected to complete payment before it goes live.
          </p>
        </div>
        <div className="flex justify-end pt-2">
          <Button
            disabled={!title.trim() || createMutation.isPending}
            onClick={() => createMutation.mutate()}
          >
            {createMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Create & pay $10'}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

export function CausesPage() {
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')

  const discoverQuery = useQuery({
    queryKey: ['causes', 'search', debounced],
    queryFn: () => searchCauses(debounced || undefined),
  })

  const mineQuery = useQuery({ queryKey: ['causes', 'mine'], queryFn: fetchMyCauses })

  const queryClient = useQueryClient()

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-display text-2xl font-medium">Causes</h1>
          <p className="text-sm text-muted-foreground">
            Join a cause to let your connected accounts help broadcast its media — or start your own.
          </p>
        </div>
        <CreateCauseDialog />
      </div>

      <Tabs
        defaultValue="discover"
        onValueChange={() => queryClient.invalidateQueries({ queryKey: ['causes', 'mine'] })}
      >
        <TabsList>
          <TabsTrigger value="discover">Discover</TabsTrigger>
          <TabsTrigger value="mine">Mine</TabsTrigger>
        </TabsList>

        <TabsContent value="discover" className="flex flex-col gap-4">
          <div className="relative max-w-sm">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              className="pl-9"
              placeholder="Search causes or goals…"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                window.clearTimeout((window as any).__causeSearchTimeout)
                ;(window as any).__causeSearchTimeout = window.setTimeout(() => setDebounced(e.target.value), 300)
              }}
            />
          </div>

          {discoverQuery.isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : discoverQuery.data?.data.length ? (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {discoverQuery.data.data.map((cause) => (
                <CauseCard key={cause.id} cause={cause} />
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">No causes found.</p>
          )}
        </TabsContent>

        <TabsContent value="mine" className="flex flex-col gap-6">
          <div>
            <h2 className="mb-3 text-sm font-medium text-muted-foreground">Owned by you</h2>
            {mineQuery.data?.owned.length ? (
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {mineQuery.data.owned.map((cause) => (
                  <CauseCard key={cause.id} cause={cause} />
                ))}
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">You haven't created a cause yet.</p>
            )}
          </div>

          <div>
            <h2 className="mb-3 text-sm font-medium text-muted-foreground">Memberships</h2>
            {mineQuery.data?.memberships.length ? (
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {mineQuery.data.memberships.map((m) => (
                  <div key={m.id} className="relative">
                    <CauseCard cause={m.cause} />
                    <Badge className="absolute right-2 top-2 text-xs capitalize" variant={m.status === 'joined' ? 'default' : 'secondary'}>
                      {m.status.replace('_', ' ')}
                    </Badge>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">No invitations or memberships yet.</p>
            )}
          </div>
        </TabsContent>
      </Tabs>
    </div>
  )
}
