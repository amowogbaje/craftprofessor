import { useEffect } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Film } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { setToken } from '@/lib/token'

export function OAuthCallbackPage() {
  const [params] = useSearchParams()
  const { refreshUser } = useAuth()
  const navigate = useNavigate()

  useEffect(() => {
    const token = params.get('token')
    if (!token) {
      navigate('/login')
      return
    }
    setToken(token)
    refreshUser().then(() => navigate('/dashboard'))
  }, [params, navigate, refreshUser])

  return (
    <div className="flex min-h-screen items-center justify-center text-muted-foreground">
      <Film className="h-6 w-6 animate-pulse" />
    </div>
  )
}
