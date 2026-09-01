import { Routes, Route, Navigate } from 'react-router-dom'
import { ProtectedRoute } from '@/components/ProtectedRoute'
import { AppLayout } from '@/components/layout/AppLayout'
import { LoginPage } from '@/pages/auth/LoginPage'
import { RegisterPage } from '@/pages/auth/RegisterPage'
import { VerifyOtpPage } from '@/pages/auth/VerifyOtpPage'
import { ForgotPasswordPage } from '@/pages/auth/ForgotPasswordPage'
import { ResetPasswordPage } from '@/pages/auth/ResetPasswordPage'
import { OAuthCallbackPage } from '@/pages/auth/OAuthCallbackPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { StoriesPage } from '@/pages/StoriesPage'
import { StoryDetailPage } from '@/pages/StoryDetailPage'
import { StoryCharactersPage, SeriesCharactersPage } from '@/pages/CharactersPage'
import { SeriesPage } from '@/pages/SeriesPage'
import { SeriesDetailPage } from '@/pages/SeriesDetailPage'
import { StatsPage } from '@/pages/StatsPage'
import { WalletPage } from '@/pages/WalletPage'
import { SettingsPage } from '@/pages/SettingsPage'
import { NotFoundPage } from '@/pages/NotFoundPage'
import { SocialAccountsPage } from '@/pages/SocialAccountsPage'
import { PinterestBoardsPage } from '@/pages/PinterestBoardsPage'
import { CausesPage } from '@/pages/CausesPage'
import { CauseDetailPage } from '@/pages/CauseDetailPage'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register" element={<RegisterPage />} />
      <Route path="/verify-otp" element={<VerifyOtpPage />} />
      <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      <Route path="/reset-password" element={<ResetPasswordPage />} />
      <Route path="/oauth/callback" element={<OAuthCallbackPage />} />

      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/stories" element={<StoriesPage />} />
          <Route path="/stories/:slug" element={<StoryDetailPage />} />
          <Route path="/stories/:slug/characters" element={<StoryCharactersPage />} />
          <Route path="/series" element={<SeriesPage />} />
          <Route path="/series/:slug" element={<SeriesDetailPage />} />
          <Route path="/series/:slug/characters" element={<SeriesCharactersPage />} />
          <Route path="/stats" element={<StatsPage />} />
          <Route path="/wallet" element={<WalletPage />} />
          <Route path="/settings" element={<SettingsPage />} />
          <Route path="/settings/social-accounts" element={<SocialAccountsPage />} />
          <Route path="/settings/pinterest-boards" element={<PinterestBoardsPage />} />
          <Route path="/causes" element={<CausesPage />} />
          <Route path="/causes/:idOrSlug" element={<CauseDetailPage />} />
        </Route>
      </Route>

      <Route path="/" element={<Navigate to="/dashboard" replace />} />
      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  )
}
