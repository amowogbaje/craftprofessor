# User-Centric Rebuild — What Changed

This turns the app from a single-tenant automation pipeline (you pasting
Medium links, the app posting to Pinterest) into a multi-user product: every
user has their own account, stories, prompts, images, and now videos, gated
by a coin balance they pay for. Below is exactly what was built, what you
need to configure, and what's intentionally left for a follow-up pass.

## 1. Install new dependencies

Not included in the code (composer.json wasn't in the upload), so add these:

```bash
composer require firebase/php-jwt laravel/socialite
```

`firebase/php-jwt` is the actively-maintained, framework-agnostic JWT
library (not `tymon/jwt-auth`, which has had long unmaintained stretches and
lagged behind newer Laravel/PHP versions — deliberately avoided here).
Auth is now fully stateless JWT: no Sanctum, no token table to write on
every request. If `laravel/sanctum` and its `personal_access_tokens`
migration are still in your project from before, they're no longer used by
anything here and can be removed (or just left inert).

Generate a signing secret once:

```bash
php artisan jwt:secret   # prints a JWT_SECRET value to paste into .env
```

### How the JWT auth works

- Every access token carries `sub` (user id), `tv` (the user's
  `token_version` at issue time), `jti` (unique token id), `purp`
  (`'access'` or `'password-reset'`), and standard `iat`/`exp`.
- `App\Providers\AppServiceProvider::boot()` registers a stateless `jwt`
  guard via `Auth::viaRequest()`; `config/auth.php`'s `api` guard uses it.
  Protected routes use `->middleware('auth:api')` exactly like Sanctum did.
- **Single-session logout** (`POST /api/auth/logout`) blacklists that one
  token's `jti` in the `jwt_blacklist` table until it would have expired
  anyway.
- **"Log out everywhere"** happens automatically on password reset
  (`$user->invalidateAllTokens()` bumps `token_version`), which instantly
  invalidates every token issued before that point — no need to enumerate
  or blacklist them individually.
- The password-reset flow's intermediate `reset_token` is a JWT too
  (`purp: 'password-reset'`, ~10 min TTL via `JWT_RESET_TTL`), checked by
  `PasswordResetController::reset()` before it'll accept a new password.

## 2. New environment variables

```env
# JWT auth
JWT_SECRET=                # php artisan jwt:secret
JWT_ALGO=HS256
JWT_TTL=10080               # access token lifetime, minutes (default: 7 days)
JWT_RESET_TTL=10            # password-reset token lifetime, minutes

# Google OAuth (login/signup)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=${APP_URL}/api/auth/google/callback
FRONTEND_URL=            # where GoogleAuthController redirects after login

# Flutterwave (coin purchases)
FLUTTERWAVE_PUBLIC_KEY=
FLUTTERWAVE_SECRET_KEY=
FLUTTERWAVE_SECRET_HASH=  # set the same value as "verif-hash" in your Flutterwave dashboard webhook config
FLUTTERWAVE_BASE_URL=https://api.flutterwave.com/v3

# SMS OTP (Termii — swap provider in OtpService::sendSms() if you prefer another)
TERMII_API_KEY=
TERMII_SENDER_ID=

# Veo / Vertex AI (image-to-video)
GOOGLE_APPLICATION_CREDENTIALS=/path/to/service-account.json
GOOGLE_CLOUD_PROJECT_ID=
GOOGLE_CLOUD_LOCATION=us-central1
VEO_MODEL=veo-3.0-generate-001

# Coin economy (all optional, these are the defaults)
COIN_SIGNUP_BONUS=50
COIN_COST_IMAGE_PROMPT=2
COIN_COST_IMAGE_GENERATION=10
COIN_COST_VIDEO_PROMPT=3
COIN_COST_VIDEO_GENERATION=40
COIN_COST_CHARACTER_PORTRAIT=8
```

`config/coins.php` also has `packages` — the coin bundles shown on the
top-up screen (amount, currency, coins). Adjust those to match your real
Gemini/Veo/Flutterwave costs plus margin.

Add `laravel/socialite`'s config to `config/services.php` — already done
under the `google` key, just needs the env vars above.

## 3. Run migrations

```bash
php artisan migrate
```

New tables: `wallets`, `coin_transactions`, `publish_settings`,
`video_prompts`, `videos`, `jwt_blacklist`. Existing tables gained columns:
`users` (google_id, phone, OTP fields, role, token_version), `story_series` /
`stories` (user_id), `story_image_prompts` (user_id,
status/scheduled_at/published_at, coin costs).

**Existing data**: any Story/StorySeries/StoryImagePrompt rows created before
this migration will have `user_id = null`. Either backfill them to an admin
account, or leave them — they'll just never surface in anyone's dashboard
and the schedulers skip coin-charging for ownerless rows (see
`ImageGeneratorService`).

## 4. What's implemented

**Auth** (`routes/api.php`, prefix `/api/auth`)
- Email/password register + login (stateless JWT access tokens, see §1)
- Google OAuth login/signup (`GoogleAuthController`) — works for both, an
  existing email is linked rather than duplicated
- OTP verification for signup, deliverable via email or SMS (user's choice
  at registration) — `OtpController`, `OtpService`
- Password recovery: request OTP → verify OTP → reset (reset requires a
  `purp: 'password-reset'` JWT, and invalidates every other active session
  the moment the password changes)

**Ownership** — `User` now owns `storySeries`, `stories`, `imagePrompts`,
`videoPrompts`, `videos`. A user can submit their own story text directly
(`POST /api/stories`) instead of only linking Medium articles — it flows
through the exact same prompt/image pipeline (`Story::readyForPrompts()`
now matches either source).

**Video pipeline** (new) — `VideoPromptAgent` turns a generated scene image
+ its original prompt into a short motion prompt; `VideoGeneratorService`
sends that to Veo on Vertex AI (long-running operation, polled) and stores
the resulting mp4. Triggered per-image via
`POST /api/story-image-prompts/{id}/video`, runs as a queued job
(`GenerateVideoJob`) since Veo can take a few minutes.

**Dashboard** (`DashboardController`) — `GET /api/dashboard/feed` merges
images and videos into one reverse-chronological feed, each item labeled
`draft` / `scheduled` (with `scheduled_at`) / `published`, filterable by
`type` and `status`. `PATCH /api/dashboard/images/{id}` and
`/videos/{id}` let the user move something between those states. A
scheduler (`content:publish-due`, runs every minute) auto-flips anything
past its `scheduled_at` to `published`.

**Publish limits** — `GET`/`PUT /api/publish-settings` lets a user set
`daily_image_limit`, `daily_video_limit`, and optional monthly ceilings.
`UsageLimitService` enforces these *before* any coin is charged, so hitting
a limit just silently defers generation to the next eligible window instead
of wasting a paid API call.

**Coins / premium** — `WalletService` is the single choke point for every
coin movement (append-only `coin_transactions` ledger + a `wallets.balance`
cache, both updated atomically). Every generation action
(`image_prompt`, `image_generation`, `character_portrait`, `video_prompt`,
`video_generation`) is charged *before* the AI call and automatically
refunded if that call fails — so a user is never charged for a failed
generation, and generation is hard-gated by what they can actually afford.
`POST /api/wallet/topup` starts a Flutterwave checkout; both the redirect
callback and the `/webhooks/flutterwave` webhook verify the transaction
server-side before crediting (idempotent on `tx_ref`, so a replay can't
double-credit).

## 5. What's NOT built yet (next steps)

- **Frontend** — everything above is API-only (Sanctum token auth). There's
  no dashboard UI. Given the app has no SPA scaffold currently (just Blade +
  a bare `app.js`), I'd suggest either a small Vue/React SPA consuming these
  endpoints, or Blade + Livewire if you want to stay server-rendered — happy
  to build either once you pick.
- **Admin surface** — no admin panel to manually credit coins, review
  refunds, or moderate content. `role` column exists on `users` for this.
- **Queue worker / horizon config** — `GenerateVideoJob` assumes a `videos`
  queue is being worked (`php artisan queue:work --queue=videos,default`).
- The legacy `GenerateImagePrompts` console command referenced classes
  (`GenerateCharacterImageJob`, `GenerateSceneImageJob`, an undefined
  `--limit` option) that don't exist in the codebase as uploaded — that
  looks like it predates this session and needs a look independent of this
  rebuild.

Let me know which of these you want next — the frontend dashboard is
probably the highest-impact one now that the API underneath it is real.
