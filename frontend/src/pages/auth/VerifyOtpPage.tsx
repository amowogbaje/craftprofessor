import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { AuthLayout } from '@/components/layout/AuthLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { resendOtp, verifySignupOtp } from '@/lib/api-auth'
import { apiErrorMessage } from '@/lib/http'
import { useAuth } from '@/context/AuthContext'
import { useToast } from '@/components/ui/use-toast'
import type { OtpChannel } from '@/lib/types'

export function VerifyOtpPage() {
  const location = useLocation() as { state?: { email?: string; otpChannel?: OtpChannel } }
  const email = location.state?.email ?? ''
  const otpChannel = location.state?.otpChannel ?? 'email'
  const [code, setCode] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isResending, setIsResending] = useState(false)
  const { login } = useAuth()
  const { toast } = useToast()
  const navigate = useNavigate()

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault()
    setIsSubmitting(true)
    try {
      const { token, user } = await verifySignupOtp(email, code)
      login(token, user)
      navigate('/dashboard')
    } catch (err) {
      toast({ title: 'Invalid code', description: apiErrorMessage(err), variant: 'destructive' })
    } finally {
      setIsSubmitting(false)
    }
  }

  async function onResend() {
    setIsResending(true)
    try {
      await resendOtp(email, otpChannel, 'signup')
      toast({ title: 'Code sent', description: `Check your ${otpChannel === 'sms' ? 'phone' : 'email'}.` })
    } catch (err) {
      toast({ title: 'Could not resend code', description: apiErrorMessage(err), variant: 'destructive' })
    } finally {
      setIsResending(false)
    }
  }

  return (
    <AuthLayout
      title="Verify your account"
      subtitle={email ? `Enter the code we sent to ${email}` : 'Enter your verification code'}
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-4">
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
          {isSubmitting ? 'Verifying…' : 'Verify'}
        </Button>
      </form>

      <button
        onClick={onResend}
        disabled={isResending}
        className="mt-4 w-full text-center text-sm text-primary hover:underline disabled:opacity-50"
      >
        {isResending ? 'Sending…' : "Didn't get a code? Resend"}
      </button>
    </AuthLayout>
  )
}
