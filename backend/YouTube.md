# YouTube Publishing — Setup & Re-Authorization Runbook

This doc covers everything needed to authorize this project to publish to
YouTube (long-form + Shorts), and to re-authorize or rotate credentials
later. It's written as a runbook: follow it top to bottom the first time,
then jump straight to "Re-authorizing / rotating credentials" if you're
just fixing a broken connection.

**Status: most of the code this doc describes already exists in the repo.**
This isn't a spec for code to write — it's the missing setup doc for
integrations that were already built:

- OAuth connect/callback flow: `App\Http\Controllers\Api\SocialOAuthController`
  + `App\Services\SocialPlatforms\Support\OAuthProviderConfig` (the
    `'youtube'` case).
- Upload + publish logic: `App\Services\SocialPlatforms\YouTubePlatform`.
- Platform registration: `App\Services\SocialPlatforms\SocialPlatformManager`.
- Daily Shorts posting job: `App\Console\Commands\PostYouTubeShorts`
  (`php artisan youtube:post-daily-short`), scheduled in
  `routes/console.php`.
- Shorts-length trimming: `App\Services\YouTubeShortsExportService`.

What was genuinely missing and is new in this change:
`PostYouTubeShorts`, `YouTubeShortsExportService`, automatic token refresh
inside `YouTubePlatform` (see "Refresh tokens" below), the
`story_videos.posted_to_youtube`/`youtube_video_id`/etc. tracking columns,
and this doc.

---

## 1. Create/configure the Google Cloud project

1. Go to the [Google Cloud Console](https://console.cloud.google.com/) and
   create a new project (or reuse an existing one for this app — a
   dedicated project is cleaner for quota isolation, see §4).
2. **Enable the YouTube Data API v3**: APIs & Services → Library → search
   "YouTube Data API v3" → Enable.
3. **Configure the OAuth consent screen** (APIs & Services → OAuth consent
   screen):
   - User type: "External" (unless the connected channel is on a Google
     Workspace domain you control, in which case "Internal" is simpler and
     skips the verification step below).
   - Add the scopes from §2 below.
   - Add the Google account(s) that will connect a YouTube channel as
     **Test users** while the app is in "Testing" mode — this lets you
     connect and post without going through Google's verification review.
   - **Verification**: Google requires app verification (including a
     restricted-scope security assessment) before an app in "Production"
     status can request `youtube.upload` for arbitrary users. For a
     single-channel/internal tool, staying in "Testing" mode with the
     channel's Google account added as a test user avoids this entirely —
     only revisit verification if this needs to onboard external users'
     own channels.

## 2. Create OAuth 2.0 credentials

This project uses **OAuth 2.0 (user-consent), not a service account** —
matching the existing `OAuthProviderConfig`/`SocialOAuthController` flow
that already drives LinkedIn/Twitter/Instagram/Facebook the same way. A
service account can't post to a personal/brand YouTube channel on its own
(there's no "service account owns a channel" concept the way there is for,
e.g., a GCS bucket), so OAuth is the only fit here regardless of
preference.

### Reusing your existing "Sign in with Google" OAuth client

If this app already has `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` set for
Google sign-in (`App\Http\Controllers\Auth\GoogleAuthController`), **you
don't need a second OAuth client** — `config('services.youtube.client_id')`
falls back to those same two values when `YOUTUBE_CLIENT_ID`/
`YOUTUBE_CLIENT_SECRET` aren't set (see `config/services.php`). This is
the normal case; only create a dedicated client (§ steps below) if you
specifically want YouTube on a separate one.

Reusing it means exactly two things still need doing in Cloud Console
against that *existing* client/project — both one-time:

1. **Add the YouTube scopes to that project's OAuth consent screen** —
   `https://www.googleapis.com/auth/youtube.upload` and
   `https://www.googleapis.com/auth/youtube.readonly` (APIs & Services →
   OAuth consent screen → Scopes → Add or remove scopes). A consent
   screen's scope list is per-project, not per-client, but Google still
   needs these explicitly added before any client under that project can
   request them — sign-in-only scopes (`openid`, `email`, `profile`)
   being there already doesn't cover this.
2. **Add the YouTube callback as an authorized redirect URI on that same
   client** — Credentials → your existing OAuth 2.0 Client ID → Authorized
   redirect URIs → Add URI → `{APP_URL}/api/social/youtube/callback`
   (i.e. whatever `YOUTUBE_REDIRECT_URI` is set to). Google validates
   `redirect_uri` per client, so the sign-in flow's own callback URL being
   registered doesn't automatically cover this different one — skip this
   and the connect flow fails with `redirect_uri_mismatch`.
3. Also **enable the YouTube Data API v3** on that project if it isn't
   already (§1 above) — enabling it is per-project, same as the consent
   screen.

That's it — no new Client ID/Secret, `YOUTUBE_CLIENT_ID`/
`YOUTUBE_CLIENT_SECRET` stay unset in `.env`, and connecting a channel
works the same way described in §3 below.

### Creating a separate, dedicated OAuth client instead

Only do this if you want YouTube on its own client rather than reusing
Google sign-in's (e.g. you'd rather rotate/scope them independently):

1. APIs & Services → Credentials → Create Credentials → OAuth client ID.
2. Application type: **Web application**.
3. Authorized redirect URI: the value you'll set as `YOUTUBE_REDIRECT_URI`
   below — for this app that's
   `{APP_URL}/api/social/youtube/callback` (see the generic
   `SocialOAuthController::callback` route in `routes/api.php`).
4. Save the generated **Client ID** and **Client Secret** into
   `YOUTUBE_CLIENT_ID`/`YOUTUBE_CLIENT_SECRET` — with both set, they take
   priority over the `GOOGLE_CLIENT_ID`/`SECRET` fallback above.

### Required OAuth scopes

Already set in `OAuthProviderConfig::for('youtube')`:

- `https://www.googleapis.com/auth/youtube.upload` — required to upload
  videos.
- `https://www.googleapis.com/auth/youtube.readonly` — used by
  `SocialOAuthController::enrichYouTube()` to look up the connected
  channel's id/name right after connecting (`channels?mine=true`).

## 3. Connect a channel / generate a refresh token

1. Set `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET`, and
   `YOUTUBE_REDIRECT_URI` in `.env` (see `.env.example`).
2. From the app's Social Accounts settings page, click "Connect" on
   YouTube — this hits `GET /api/social/youtube/connect`
   (`SocialOAuthController::connect`), which redirects to Google's
   consent screen with `access_type=offline&prompt=consent` already
   included (`OAuthProviderConfig`'s `extra_authorize_params`). **Both of
   those params matter**: `access_type=offline` is what makes Google issue
   a `refresh_token` at all, and `prompt=consent` forces the consent
   screen (and a fresh refresh token) even if this Google account already
   granted access before — Google otherwise silently omits the
   `refresh_token` on a repeat authorization.
3. Sign in with the Google account that owns/manages the target YouTube
   channel and grant access.
4. Google redirects back to the callback route, which exchanges the code
   for tokens and stores them on a `social_accounts` row
   (`provider = 'youtube'`): `access_token`, `refresh_token`,
   `token_expires_at`, `scopes`. `SocialOAuthController::enrichYouTube()`
   then fills in `provider_user_id` (channel id) and `provider_username`
   (channel title).
5. Done — no manual token handling needed. If you ever need the raw
   refresh token outside the app (e.g. for a one-off script), it's the
   `refresh_token` column on that `social_accounts` row (see §5 —
   `access_token`/`refresh_token` are stored `encrypted`, so read them
   through Eloquent, not a raw DB query).

## 4. Quota limits & daily upload cost

- Every Google Cloud project gets a **default YouTube Data API v3 quota of
  10,000 units/day**, shared across all API calls that project makes.
- A `videos.insert` call (i.e. one upload) costs **1,600 units**. The
  `channels.list` call used for identity enrichment on connect costs 1
  unit and is negligible.
- At 1,600 units/upload, the default 10,000-unit budget supports **up to 6
  uploads/day** before hitting the cap — comfortably more than the 1
  Short/day this project posts (§ "Daily posting" below), leaving headroom
  for manual long-form uploads or retries on a failed day.
- If this project's Google Cloud project is ever shared with other
  YouTube API usage, that usage draws from the same 10,000-unit pool —
  worth a dedicated Cloud project per §1 if that's a concern.
- Quota resets at midnight Pacific Time daily, and does not roll over.
- If more than 6 uploads/day is ever needed, quota increases can be
  requested via the Cloud Console's Quotas page — Google reviews these
  case by case and typically wants a completed OAuth verification (§1)
  first.

## 5. Where credentials/tokens live

Matches the existing convention for every other platform in this app
(Pinterest is the one exception — see the note below):

| What | Where |
|---|---|
| App-level `client_id`/`client_secret`/`redirect_uri` | `.env` → `YOUTUBE_CLIENT_ID`/`SECRET` (falls back to `GOOGLE_CLIENT_ID`/`SECRET` if unset — see §2), `YOUTUBE_REDIRECT_URI`, read via `config/services.php`'s `'youtube'` key |
| Per-user `access_token`/`refresh_token`/`token_expires_at` | `social_accounts` table, one row per user per provider (`encrypted` cast — see `App\Models\SocialAccount`) |
| Per-user channel id/name | `social_accounts.provider_user_id` / `provider_username` |
| Daily posting cap + its reset timezone | `.env` → `YOUTUBE_MAX_SHORTS_PER_USER_PER_DAY`, `YOUTUBE_DAILY_CAP_TIMEZONE`, read via `config('services.youtube.*')` |
| Per-post status/history | `social_posts` table (`platform = 'youtube'`, `story_video_id` set) |

Pinterest keeps its own dedicated connect flow
(`App\Services\PinterestService` / `SocialAccountController`) predating
this generic OAuth path, rather than going through
`OAuthProviderConfig`/`SocialOAuthController` — that's the one place this
app's per-platform credential handling isn't uniform, and it's
intentional (see that class's doc-comments), not something to "fix" as
part of YouTube work.

## 6. Refresh tokens

Google access tokens are short-lived (~1 hour); the `refresh_token`
obtained in §3 is what keeps posting working without re-consenting.

**Automatic refresh** happens in `YouTubePlatform::forAccount()` /
`ensureFreshToken()` — every time the app is about to act as a connected
YouTube account (uploading, etc.), it first checks
`social_accounts.token_expires_at` and, if it's expired or expiring within
5 minutes, calls Google's token endpoint
(`https://oauth2.googleapis.com/token`, `grant_type=refresh_token`) and
persists the new `access_token`/`token_expires_at` back to that row. This
mirrors `PinterestService::ensureFreshToken()`'s existing pattern for
Pinterest. Because this happens on-demand rather than on a fixed schedule,
there's no separate `youtube:refresh-tokens` command (unlike Pinterest,
which does refresh proactively ahead of its posting window — see
`RefreshPinterestTokens`); a token that's already fresh is a no-op check,
so there's no cost to leaving refresh inline here.

**If refresh ever fails outright** (e.g. the refresh token itself was
revoked — the user removed the app's access in their Google Account, or it
expired from 6 months of inactivity on a "Testing"-mode consent screen),
`ensureFreshToken()` logs the failure to the `youtube` log channel and
returns the stale account rather than throwing; the subsequent API call
then fails with a clear 401, which `PostYouTubeShorts` records as
`last_youtube_error` on the `story_videos` row. **Recovery**: the user has
to reconnect from Settings → Social Accounts (repeats §3) to get a new
refresh token — there's no way to programmatically recover a revoked
refresh token.

## 7. Re-authorizing / rotating credentials

- **Rotating the app-level Client Secret** (e.g. suspected leak): create a
  new secret for the same OAuth client in Cloud Console, update
  `YOUTUBE_CLIENT_SECRET` in `.env`, deploy. Existing users' stored
  refresh tokens keep working — the secret is only used at token-exchange
  time, and Google doesn't invalidate outstanding refresh tokens when you
  rotate a client secret.
- **Re-authorizing a single user's channel** (revoked/expired refresh
  token, or connecting a different channel): have them click
  "Disconnect" then "Connect" again on the Social Accounts settings page
  (or just "Connect" again if there's no disconnect option surfaced yet —
  the connect flow's `updateOrCreate` on `social_accounts` overwrites the
  existing row for that user+provider either way).
- **Moving to a new Google Cloud project entirely** (e.g. quota
  separation): repeat §1–§2 for the new project, update all three
  `YOUTUBE_*` env vars, and have every connected user reconnect (§3) —
  refresh tokens are tied to the OAuth client that issued them and won't
  carry over to a different Client ID.

## 8. Shorts constraints (checked against current YouTube limits)

- **Max length: 3 minutes (180s)** — raised from the original 60s limit in
  October 2024. Any vertical/square video at or under this length uploaded
  normally is automatically eligible to appear in the Shorts feed; there's
  no separate "Shorts" upload endpoint, just a regular `videos.insert`
  whose dimensions/duration qualify.
- **Aspect ratio: vertical (9:16)** is what actually matters for Shorts
  eligibility (square also qualifies). This app's assembled story videos
  are already rendered at 1080×1920 (see
  `StoryVideoAssemblyService::WIDTH`/`HEIGHT`), so no extra cropping step
  was needed — `YouTubeShortsExportService` only has to handle the length
  limit (trimming anything over 180s), not aspect ratio.
- **No minimum length**, though anything under a few seconds is unlikely
  to get picked up by the Shorts feed/algorithm in practice.
- Recheck these before relying on them long-term — YouTube has changed the
  Shorts length limit before (60s → 180s in 2024) and could again.
