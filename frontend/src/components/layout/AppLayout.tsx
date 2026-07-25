import { NavLink, Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Coins, Film, LayoutGrid, LogOut, Settings, BookOpen, Layers, Link2, BarChart3 } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { fetchWallet } from '@/lib/api-wallet'
import { cn } from '@/lib/utils'

const NAV_ITEMS = [
  { to: '/dashboard', label: 'Feed', icon: LayoutGrid },
  { to: '/stories', label: 'Stories', icon: BookOpen },
  { to: '/series', label: 'Series', icon: Layers },
  { to: '/stats', label: 'Stats', icon: BarChart3 },
  { to: '/settings/social-accounts', label: 'Socials', icon: Link2 },
  { to: '/settings', label: 'Publish limits', icon: Settings },
]

export function AppLayout() {
  const { user, logout } = useAuth()
  const { data: wallet } = useQuery({ queryKey: ['wallet'], queryFn: fetchWallet })

  return (
    <div className="flex min-h-screen">
      <aside className="hidden w-60 shrink-0 flex-col border-r border-border bg-card/40 md:flex">
        <div className="flex h-16 items-center gap-2 px-5">
          <div className="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
            <Film className="h-4 w-4" />
          </div>
          <span className="font-display text-lg font-medium">Storyframe</span>
        </div>

        <nav className="flex flex-1 flex-col gap-1 px-3 py-4">
          {NAV_ITEMS.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground',
                  isActive && 'bg-secondary text-foreground',
                )
              }
            >
              <Icon className="h-4 w-4" />
              {label}
            </NavLink>
          ))}
        </nav>

        <NavLink
          to="/wallet"
          className={({ isActive }) =>
            cn(
              'mx-3 mb-3 flex items-center justify-between rounded-md border border-border px-3 py-2.5 text-sm transition-colors hover:bg-secondary',
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

        <div className="flex items-center justify-between border-t border-border px-5 py-4">
          <div className="min-w-0">
            <p className="truncate text-sm font-medium">{user?.name}</p>
            <p className="truncate text-xs text-muted-foreground">{user?.email}</p>
          </div>
          <button
            onClick={() => logout()}
            className="rounded-md p-2 text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
            title="Log out"
          >
            <LogOut className="h-4 w-4" />
          </button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-16 items-center justify-between border-b border-border px-5 md:hidden">
          <span className="font-display text-lg font-medium">Storyframe</span>
          <span className="flex items-center gap-1.5 font-mono text-sm">
            <Coins className="h-4 w-4 text-scheduled" />
            {wallet?.balance ?? '—'}
          </span>
        </header>
        <main className="flex-1 px-5 py-6 md:px-8 md:py-8">
          <Outlet />
        </main>
        <nav className="flex items-center justify-around border-t border-border bg-card/60 py-2 md:hidden">
          {NAV_ITEMS.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              className={({ isActive }) =>
                cn('flex flex-col items-center gap-1 px-2 py-1 text-[11px] text-muted-foreground', isActive && 'text-foreground')
              }
            >
              <Icon className="h-4 w-4" />
              {label}
            </NavLink>
          ))}
        </nav>
      </div>
    </div>
  )
}
