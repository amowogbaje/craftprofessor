import * as DialogPrimitive from '@radix-ui/react-dialog'
import { NavLink } from 'react-router-dom'
import type { LucideIcon } from 'lucide-react'
import { Coins, LogOut } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { cn } from '@/lib/utils'
import { useAuth } from '@/context/AuthContext'
import { fetchWallet } from '@/lib/api-wallet'

export interface MoreNavItem {
  to: string
  label: string
  icon: LucideIcon
}

/**
 * The mobile bottom nav only has room for a handful of items before it
 * gets cramped — this is where the rest live. Reuses @radix-ui/react-dialog
 * directly (rather than the shared centered Dialog) so it can render as a
 * bottom sheet instead of a centered card.
 */
export function MobileMoreSheet({
  items,
  open,
  onOpenChange,
}: {
  items: MoreNavItem[]
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { logout } = useAuth()
  const { data: wallet } = useQuery({ queryKey: ['wallet'], queryFn: fetchWallet, enabled: open })

  return (
    <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-background/80 backdrop-blur-sm data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />
        <DialogPrimitive.Content
          className="fixed inset-x-0 bottom-0 z-50 max-h-[75vh] overflow-y-auto rounded-t-xl border-t border-border bg-card p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-lg duration-200 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom"
        >
          <DialogPrimitive.Title className="sr-only">More</DialogPrimitive.Title>
          <DialogPrimitive.Description className="sr-only">Additional navigation and account options</DialogPrimitive.Description>

          <div className="mx-auto mb-3 h-1 w-10 shrink-0 rounded-full bg-border" />

          <NavLink
            to="/wallet"
            onClick={() => onOpenChange(false)}
            className={({ isActive }) =>
              cn(
                'mb-2 flex items-center justify-between rounded-md border border-border px-3 py-2.5 text-sm transition-colors hover:bg-secondary',
                isActive && 'bg-secondary',
              )
            }
          >
            <span className="flex items-center gap-2 text-muted-foreground">
              <Coins className="h-4 w-4 text-scheduled" />
              Coins
            </span>
            <span className="font-mono text-sm font-semibold">{wallet?.balance ?? '—'}</span>
          </NavLink>

          <div className="flex flex-col gap-1">
            {items.map(({ to, label, icon: Icon }) => (
              <NavLink
                key={to}
                to={to}
                onClick={() => onOpenChange(false)}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground',
                    isActive && 'bg-secondary text-foreground',
                  )
                }
              >
                <Icon className="h-4 w-4" />
                {label}
              </NavLink>
            ))}

            <button
              type="button"
              onClick={() => {
                onOpenChange(false)
                logout()
              }}
              className="flex items-center gap-3 rounded-md px-3 py-2.5 text-left text-sm font-medium text-destructive transition-colors hover:bg-destructive/10"
            >
              <LogOut className="h-4 w-4" />
              Log out
            </button>
          </div>
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  )
}
