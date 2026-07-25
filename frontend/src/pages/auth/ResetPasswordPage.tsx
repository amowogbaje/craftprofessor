import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { AuthLayout } from '@/components/layout/AuthLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { verifyPasswordResetOtp, resetPassword } from '@/lib/api-auth'
import { apiErrorMessage } from '@/lib/http'
import { useAuth } from '@/context/AuthContext'
import { useToast } from '@/components/ui/use-toast'

export function ResetPasswordPage() {
  const location = useLocation() as { state?: { email?: string } }
  const email = location.state?.email ?? ''
  const [step, setStep] = useState<'code' | 'password'>('code')
  const [code, setCode] = useState('')
  const [resetToken, setResetToken] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const { login } = useAuth()
  const { toast } = useToast()
  const navigate = useNavigate()

  async function onVerifyCode(e: React.FormEvent) {
    e.preventDefault()
    setIsSubmitting(true)
    try {
      const { reset_token } = await verifyPasswordResetOtp(email, code)
      setResetToken(reset_token)
      setStep('password')
    } catch (err) {
      toast({ title: 'Invalid code', description: apiErrorMessage(err), variant: 'destructive' })
    } finally {
      setIsSubmitting(false)
    }
  }

  async function onSetPassword(e: React.FormEvent) {
    e.preventDefault()
    setIsSubmitting(true)
    try {
      const { token } = await resetPassword(resetToken, password, passwordConfirmation)
      // We only have the token here — refreshUser() inside login-less flows
      // isn't wired for a bare token, so store it and let AuthContext hydrate.
      localStorage.setItem('storyframe_token', token)
      window.location.href = '/dashboard'
    } catch (err) {
      toast({ title: 'Could not reset password', description: apiErrorMessage(err), variant: 'destructive' })
    } finally {
      setIsSubmitting(false)
    }
  }

  if (step === 'code') {
    return (
      <AuthLayout title="Enter your code" subtitle={email ? `Sent to ${email}` : undefined}>
        <form onSubmit={onVerifyCode} className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="code">Verification code</Label>
            <Input
              id="code"
              inputMode="numeric"
              autoFocus
              maxLength={6}
              required
              className="text-center font-mono text-lg tracking-[0.5em]"
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
            />
          </div>
          <Button type="submit" disabled={isSubmitting || code.length < 6}>
            {isSubmitting ? 'Verifying…' : 'Verify code'}
          </Button>
        </form>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title="Choose a new password">
      <form onSubmit={onSetPassword} className="flex flex-col gap-4">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="password">New password</Label>
          <Input
            id="password"
            type="password"
            required
            minLength={8}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="password_confirmation">Confirm new password</Label>
          <Input
            id="password_confirmation"
            type="password"
            required
            minLength={8}
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
          />
        </div>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Saving…' : 'Save new password'}
        </Button>
      </form>
    </AuthLayout>
  )
}
