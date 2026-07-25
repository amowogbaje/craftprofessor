# Storyframe Frontend

Vite + React + TypeScript + Tailwind + shadcn-style components, talking to
the Laravel API over CORS with JWT bearer auth.

## Setup

```bash
npm install
cp .env.example .env   # set VITE_API_URL to your Laravel API root, e.g. http://localhost:8000/api
npm run dev
```

## Backend CORS

Your Laravel app needs to allow this origin. In `config/cors.php`:

```php
'paths' => ['api/*'],
'allowed_origins' => ['http://localhost:5173'], // or your deployed frontend URL
'allowed_methods' => ['*'],
'allowed_headers' => ['*'],
```

## What's built

- **Auth**: email/password login + register, Google OAuth (full-page
  redirect to `/api/auth/google/redirect`, backend bounces back to
  `/oauth/callback?token=...`), OTP verification (email or SMS), forgot/reset
  password. JWT stored in `localStorage`; axios attaches it and auto-logs-out
  on a 401.
- **The reel** (`/dashboard`): the merged image/video feed — infinite
  scroll (`useInfiniteQuery` + `IntersectionObserver` sentinel, no
  "load more" button), search-by-prompt (debounced), status filter
  (draft/scheduled/published), type filter (image/video), and sort
  (newest/oldest/upcoming schedule). Thumbnails lazy-load with a skeleton
  placeholder and fade in once decoded — nothing large is fetched until
  it's about to scroll into view. Click a card to open the detail dialog:
  publish now, move to draft, schedule (datetime picker), or (for images)
  queue video generation.
- **Stories** (`/stories`): paste story text, see generation status.
- **Series** (`/series`): link ordered Medium episodes into one series.
- **Wallet** (`/wallet`): coin balance, cost reference, package top-up
  (redirects to Flutterwave checkout), transaction history.
- **Publish limits** (`/settings`): daily image/video generation caps.

## Notes on the feed pagination

The backend's `/dashboard/feed` now takes `search`, `sort`, `cursor`, and
`per_page`, and returns `{ data, next_cursor }` instead of a flat array —
that's a breaking change from the first version of `DashboardController`,
already applied to the backend zip alongside this one. If you're merging
this into an existing checkout, make sure you're on the matching
`DashboardController`.
