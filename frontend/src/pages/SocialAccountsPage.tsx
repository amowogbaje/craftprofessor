import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Youtube, Linkedin, Twitter, Instagram, Facebook, Settings2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  fetchSocialAccounts,
  getPinterestConnectUrl,
  getConnectUrl,
  disconnectSocialAccount,
  SocialAccount,
  SocialProvider,
} from '@/lib/api-social'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'

const PROVIDERS: { key: SocialProvider; label: string; icon: React.ReactNode }[] = [
  { key: 'pinterest', label: 'Pinterest', icon: <span className="font-bold text-red-600">P</span> },
  { key: 'linkedin', label: 'LinkedIn', icon: <Linkedin className="h-5 w-5" /> },
  { key: 'twitter', label: 'X / Twitter', icon: <Twitter className="h-5 w-5" /> },
  { key: 'youtube', label: 'YouTube', icon: <Youtube className="h-5 w-5" /> },
  { key: 'instagram', label: 'Instagram', icon: <Instagram className="h-5 w-5" /> },
  { key: 'facebook', label: 'Facebook', icon: <Facebook className="h-5 w-5" /> },
]

export function SocialAccountsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({ queryKey: ['social-accounts'], queryFn: fetchSocialAccounts })

  const connectMutation = useMutation({
    mutationFn: (provider: SocialProvider) => (provider === 'pinterest' ? getPinterestConnectUrl() : getConnectUrl(provider)),
    onSuccess: (url) => {
      window.location.href = url
    },
    onError: (err) => toast({ title: 'Could not start connection', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  const disconnectMutation = useMutation({
    mutationFn: disconnectSocialAccount,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['social-accounts'] })
      toast({ title: 'Disconnected' })
    },
    onError: (err) => toast({ title: 'Could not disconnect', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  const connected = new Map((data?.data ?? []).map((a: SocialAccount) => [a.provider, a]))

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-2xl font-medium">Connected accounts</h1>
        <p className="text-sm text-muted-foreground">Link social accounts to publish directly from CraftProfessor.</p>
      </div>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {PROVIDERS.map((provider) => {
            const account = connected.get(provider.key)
            const isConfigured = data?.configured_providers?.[provider.key] ?? false
            return (
              <Card key={provider.key}>
                <CardHeader className="flex flex-row items-center gap-3 space-y-0">
                  {provider.icon}
                  <div>
                    <CardTitle className="text-base">{provider.label}</CardTitle>
                    <CardDescription>
                      {account ? `@${account.provider_username ?? 'connected'}` : 'Not connected'}
                    </CardDescription>
                  </div>
                </CardHeader>
                <CardContent className="flex flex-col gap-2">
                  {account && (
                    <div className="flex flex-wrap gap-1">
                      {account.scopes.map((scope) => (
                        <Badge key={scope} variant="secondary" className="text-xs">
                          {scope}
                        </Badge>
                      ))}
                    </div>
                  )}

                  <div className="flex flex-wrap gap-2">
                    {account ? (
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={disconnectMutation.isPending}
                        onClick={() => disconnectMutation.mutate(provider.key)}
                      >
                        {disconnectMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Disconnect'}
                      </Button>
                    ) : (
                      <Button
                        size="sm"
                        disabled={!isConfigured || connectMutation.isPending}
                        onClick={() => connectMutation.mutate(provider.key)}
                      >
                        {connectMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : isConfigured ? 'Connect' : 'Coming soon'}
                      </Button>
                    )}

                    {provider.key === 'pinterest' && account && (
                      <Button variant="outline" size="sm" asChild>
                        <Link to="/settings/pinterest-boards">
                          <Settings2 className="h-4 w-4" />
                          Boards
                        </Link>
                      </Button>
                    )}
                  </div>
                </CardContent>
              </Card>
            )
          })}
        </div>
      )}
    </div>
  )
}
