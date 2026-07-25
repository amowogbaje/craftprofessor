import { Link } from 'react-router-dom'
import { Film } from 'lucide-react'

export function NotFoundPage() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-3 text-center">
      <Film className="h-8 w-8 text-muted-foreground" />
      <h1 className="font-display text-2xl">Scene not found</h1>
      <Link to="/dashboard" className="text-sm text-primary hover:underline">
        Back to your reel
      </Link>
    </div>
  )
}
