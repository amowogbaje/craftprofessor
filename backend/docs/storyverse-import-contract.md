# StoryVerse Story JSON API — Integration Spec

This is the API StoryVerse needs to expose so CraftProfessor can import a
series and all of its episodes in one call. CraftProfessor is the
**consumer** of this endpoint; StoryVerse is the **implementer**.

**Two different hosts are involved — don't mix them up:**

| | Host | Used for |
|---|---|---|
| Reader-facing site | `storyverse.amowogbaje.com` | The public story URLs people actually paste into CraftProfessor, e.g. `https://storyverse.amowogbaje.com/stories/{slug}`. CraftProfessor only ever *validates the shape* of links on this host — it never fetches from it. |
| API | `storyverseapi.amowogbaje.com` | Where CraftProfessor actually sends the `GET /api/stories/{slug}/json` request described below. This is what this spec is about. |

---

## Overview

| | |
|---|---|
| **Method** | `GET` |
| **URL pattern** | `/api/stories/{slug}/json` on `storyverseapi.amowogbaje.com` |
| **Full example** | `https://storyverseapi.amowogbaje.com/api/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil/json` |
| **Auth** | None required — this is a public, read-only endpoint (same visibility as the public story page itself) |
| **Request body** | None |
| **Content-Type returned** | `application/json` — CraftProfessor treats any other Content-Type as a failed request (see below), so don't fall back to serving an HTML page for unmatched routes on this host/path. |

`{slug}` is the last path segment of a normal, public StoryVerse story URL
**on the reader-facing site**. For
`https://storyverse.amowogbaje.com/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil`,
the slug is `shadow-of-the-sentinel-2-the-call-beyond-the-veil`, and
CraftProfessor requests that same slug from the API host instead:
`https://storyverseapi.amowogbaje.com/api/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil/json`.

### The one rule that matters most

**Regardless of which episode's slug is requested, respond with data for
the entire series — every episode, not just the one whose slug was
requested.** CraftProfessor calls this endpoint once per import/refresh and
expects to walk away with the whole series in that single response. If a
person pastes the link for episode 4 of a 6-episode series, the response
still contains all 6 episodes.

---

## Successful response

**Status:** `200 OK`

```json
{
  "series": {
    "title": "Shadow of the Sentinel",
    "slug": "shadow-of-the-sentinel",
    "description": "A rogue AI awakens beneath the city, and only one detective believes the machines are already choosing sides.",
    "cover_image_url": "https://storyverse.amowogbaje.com/storage/covers/shadow-of-the-sentinel.jpg",
    "url": "https://storyverse.amowogbaje.com/series/shadow-of-the-sentinel"
  },
  "episodes": [
    {
      "number": 1,
      "title": "The Awakening",
      "slug": "shadow-of-the-sentinel-1-the-awakening",
      "url": "https://storyverse.amowogbaje.com/stories/shadow-of-the-sentinel-1-the-awakening",
      "content": "Full plain-text (or markdown) body of episode 1...",
      "published_at": "2026-07-03T08:00:00Z"
    },
    {
      "number": 2,
      "title": "The Call Beyond the Veil",
      "slug": "shadow-of-the-sentinel-2-the-call-beyond-the-veil",
      "url": "https://storyverse.amowogbaje.com/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil",
      "content": "Full plain-text (or markdown) body of episode 2...",
      "published_at": "2026-07-10T08:00:00Z"
    }
  ]
}
```

### `series` object

| Field | Type | Required | Notes |
|---|---|---|---|
| `title` | string | **yes** | Series title. Stored as `story_series.title` on first import. |
| `slug` | string | **yes** | **Stable identity for the series.** CraftProfessor stores this as `story_series.source_slug` (unique) and matches on it for every re-import. Must never change once published — changing it will cause CraftProfessor to create a brand-new series instead of updating the existing one. |
| `description` | string \| null | no | Free text, shown in CraftProfessor's series list. |
| `cover_image_url` | string \| null | no | Absolute URL. Shown as a thumbnail in CraftProfessor's UI. |
| `url` | string \| null | no | Canonical URL of the *series* (not a specific episode) on StoryVerse. |

### `episodes` array

Ordered or unordered — CraftProfessor sorts by `number` itself — but every
episode that currently exists for the series should be included every time.

| Field | Type | Required | Notes |
|---|---|---|---|
| `number` | integer | **yes** | 1-indexed episode number. Becomes `stories.episode_number` and determines display order. |
| `title` | string \| null | no | Episode title. Becomes `stories.title`, also used as a fallback Pinterest pin title. |
| `slug` | string \| null | no | Not used programmatically by CraftProfessor today (the full `url` is the unique key instead) — include it anyway for your own logs/debugging. |
| `url` | string | **yes** | **Must be the full, absolute, permanent story URL** for this exact episode. This is stored as `stories.story_link`, which is CraftProfessor's unique key for the episode: submitting the same `url` again on a later import updates that row instead of duplicating it. This must be stable — don't regenerate it per request. |
| `content` | string \| null | recommended | Full episode text (plain text or markdown — CraftProfessor stores it as-is). If present and different from what's already stored, CraftProfessor updates the stored text and re-arms image-prompt generation for *new* episodes only (existing episodes' generated prompts aren't wiped just because text changed). If omitted or empty, CraftProfessor leaves any existing text untouched — so you can register an episode's metadata immediately and backfill `content` in a later response once it's ready. |
| `published_at` | string (ISO 8601) \| null | no | Stored as `stories.published_at`. |

---

## Error responses

### 404 — story not found

Returned when `{slug}` doesn't correspond to any known story on StoryVerse.

**Status:** `404 Not Found`

```json
{
  "message": "Story not found."
}
```

CraftProfessor surfaces this to the person as its own distinct message
("No story could be found on StoryVerse for \"{slug}\".") rather than a
generic failure, so exact body content beyond a `message` key isn't load-bearing
— but please do return 404 (not 200 with an empty/null body, and not 500).

### Any other non-2xx status

Treated by CraftProfessor as a generic import failure — it logs the
response body and status code and shows the person a generic
"failed to import" message. It does **not** retry automatically. Reserve
this for genuine server-side problems (`500`, `503`, etc.) — anything you
can distinguish more specifically (e.g. "series temporarily unpublished")
is worth its own status/message, since CraftProfessor can be extended to
handle new distinct cases on request.

### Malformed / incomplete 200 response

Not something StoryVerse needs to send deliberately, but worth knowing:
CraftProfessor checks the `Content-Type` header first — a `200` whose body
isn't JSON (e.g. an HTML page, which typically means the request landed on
a frontend catch-all route instead of this API route) is treated as a
distinct failure ("StoryVerse returned a webpage instead of JSON..."). If
the `Content-Type` is JSON but `series.slug` or `episodes` is missing (or
`episodes` isn't an array), that's treated as a separate generic failure
("StoryVerse response for slug [...] is missing required series/episodes
fields."). Either way nothing is partially imported. So if a series is in
a broken state server-side, a clean `404`/`500` with the right
Content-Type is preferable to a `200` with an unexpected body.

---

## Idempotency & re-imports

Calling this endpoint again later (e.g. after publishing episode 3) is
expected, and should return the full, current state of the series:

- CraftProfessor matches the **series** on `series.slug` — same slug in,
  same series row updated (title/description/cover/url refreshed to
  whatever you send).
- CraftProfessor matches each **episode** on its exact `url` — same URL
  in, that episode's row is updated (number, title, published_at, and
  text if it changed); a new `url` creates a new episode. There's no
  delete/removal signal today — episodes are only ever added or updated,
  never removed by CraftProfessor based on this response.

## Validation CraftProfessor does before calling this endpoint at all

The person pastes either a bare slug or a full story URL into
CraftProfessor. Before any request is sent to StoryVerse, CraftProfessor
rejects anything that isn't a bare slug and doesn't match
`https://storyverse.amowogbaje.com/stories/{slug}` — i.e. checked against
the **reader-facing** host, since that's what people actually copy/paste,
not the API host — with its own `422` error to the person. This is
entirely client-side validation on CraftProfessor's end — nothing
StoryVerse needs to implement, just useful context for why a malformed
link never reaches your server. The extracted `{slug}` is then sent to the
**API host** (`storyverseapi.amowogbaje.com`) as described above.

---

## Example requests

```bash
# By episode 2's slug — response still contains every episode in the series
curl https://storyverseapi.amowogbaje.com/api/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil/json

# Unknown slug
curl -i https://storyverseapi.amowogbaje.com/api/stories/does-not-exist/json
# HTTP/1.1 404 Not Found
# { "message": "Story not found." }
```
