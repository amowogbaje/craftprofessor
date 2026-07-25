import type { ReactNode } from 'react'
import { Film } from 'lucide-react'

export function AuthLayout({
  title,
  subtitle,
  children,
}: {
  title: string
  subtitle?: string
  children: ReactNode
}) {
  return (
    <div className="flex min-h-screen items-center justify-center px-4 py-12">
      <div className="w-full max-w-md">
        <div className="mb-8 flex flex-col items-center gap-3 text-center">
          <div className="flex h-11 w-11 items-center justify-center rounded-md border border-border bg-card text-primary">
            <Film className="h-5 w-5" />
          </div>
          <h1 className="font-display text-2xl font-medium">{title}</h1>
          {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
        </div>
        <div className="rounded-lg border border-border bg-card p-6 shadow-xl shadow-black/20">{children}</div>
      </div>
    </div>
  )
}
