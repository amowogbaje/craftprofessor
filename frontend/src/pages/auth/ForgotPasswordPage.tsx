import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AuthLayout } from '@/components/layout/AuthLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { forgotPassword } from '@/lib/api-auth'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'
import type { OtpChannel } from '@/lib/types'

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [otpChannel, setOtpChannel] = useState<OtpChannel>('email')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const { toast } = useToast()
  const navigate = useNavigate()

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault()
    setIsSubmitting(true)
    try {
      await forgotPassword(email, otpChannel)
      navigate('/reset-password', { state: { email } })
    } catch (err) {
      toast({ title: 'Could not send code', description: apiErrorMessage(err), variant: 'destructive' })
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <AuthLayout title="Reset your password" subtitle="We'll send you a code to confirm it's you.">
      <form onSubmit={onSubmit} className="flex flex-col gap-4">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="email">Email</Label>
          <Input id="email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="otp-channel">Send code via</Label>
          <Select value={otpChannel} onValueChange={(v) => setOtpChannel(v as OtpChannel)}>
            <SelectTrigger id="otp-channel">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="email">Email</SelectItem>
              <SelectItem value="sms">SMS</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Sending…' : 'Send code'}
        </Button>
      </form>

      <p className="mt-6 text-center text-sm text-muted-foreground">
        <Link to="/login" className="text-primary hover:underline">
          Back to login
        </Link>
      </p>
    </AuthLayout>
  )
}
