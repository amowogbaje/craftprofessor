import { useMutation, useQuery } from '@tanstack/react-query'
import { Coins, Loader2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { fetchWallet, fetchTransactions, initiateTopup } from '@/lib/api-wallet'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'

function formatMoney(amount: number, currency: string) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount / 100)
}

export function WalletPage() {
  const { toast } = useToast()
  const { data: wallet, isLoading } = useQuery({ queryKey: ['wallet'], queryFn: fetchWallet })
  const { data: transactions } = useQuery({ queryKey: ['wallet-transactions'], queryFn: fetchTransactions })

  const topup = useMutation({
    mutationFn: (index: number) => initiateTopup(index),
    onSuccess: ({ payment_link }) => {
      window.location.href = payment_link
    },
    onError: (err) => toast({ title: 'Could not start checkout', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-2xl font-medium">Wallet</h1>
        <p className="text-sm text-muted-foreground">Coins pay for every generation — top up any time.</p>
      </div>

      <Card>
        <CardContent className="flex items-center gap-4 py-6">
          <div className="flex h-12 w-12 items-center justify-center rounded-md bg-scheduled/15 text-scheduled">
            <Coins className="h-6 w-6" />
          </div>
          <div>
            <p className="font-mono text-3xl font-semibold">{isLoading ? '—' : wallet?.balance}</p>
            <p className="text-sm text-muted-foreground">coins available</p>
          </div>
        </CardContent>
      </Card>

      <div>
        <h2 className="mb-3 font-display text-lg font-medium">Top up</h2>
        <div className="grid gap-3 sm:grid-cols-3">
          {wallet?.packages.map((pkg, i) => (
            <Card key={i}>
              <CardHeader>
                <CardTitle className="text-base">{pkg.label}</CardTitle>
                <CardDescription className="font-mono text-2xl text-foreground">{pkg.coins} coins</CardDescription>
              </CardHeader>
              <CardContent>
                <Button
                  className="w-full"
                  disabled={topup.isPending}
                  onClick={() => topup.mutate(i)}
                >
                  {topup.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : formatMoney(pkg.amount, pkg.currency)}
                </Button>
              </CardContent>
            </Card>
          ))}
        </div>
      </div>

      {wallet?.costs && (
        <div>
          <h2 className="mb-3 font-display text-lg font-medium">What things cost</h2>
          <Card>
            <CardContent className="grid grid-cols-2 gap-3 py-4 text-sm sm:grid-cols-3">
              <CostRow label="Image prompt" value={wallet.costs.image_prompt} />
              <CostRow label="Scene image" value={wallet.costs.image_generation} />
              <CostRow label="Character portrait" value={wallet.costs.character_portrait} />
              <CostRow label="Video prompt" value={wallet.costs.video_prompt} />
              <CostRow label="Video generation" value={wallet.costs.video_generation} />
            </CardContent>
          </Card>
        </div>
      )}

      <div>
        <h2 className="mb-3 font-display text-lg font-medium">Recent activity</h2>
        <div className="flex flex-col gap-2">
          {transactions?.data.length === 0 && <p className="text-sm text-muted-foreground">No transactions yet.</p>}
          {transactions?.data.map((tx) => (
            <Card key={tx.id}>
              <CardContent className="flex items-center justify-between py-3">
                <div>
                  <p className="text-sm capitalize">{tx.reason.replace(/_/g, ' ')}</p>
                  <p className="text-xs text-muted-foreground">{new Date(tx.created_at).toLocaleString()}</p>
                </div>
                <Badge variant={tx.type === 'credit' ? 'success' : 'outline'}>
                  {tx.type === 'credit' ? '+' : '−'}
                  {tx.amount}
                </Badge>
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    </div>
  )
}

function CostRow({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-mono">{value}</span>
    </div>
  )
}
