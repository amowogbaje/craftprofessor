# StoryVerse → CraftProfessor import contract

CraftProfessor imports a series (and every episode in it) by calling a
read-only JSON endpoint that must exist on StoryVerse, one per story slug:

```
GET https://storyverse.amowogbaje.com/api/stories/{slug}/json
```

`{slug}` is exactly the last path segment of a normal StoryVerse story URL —
e.g. for `https://storyverse.amowogbaje.com/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil`,
the slug is `shadow-of-the-sentinel-2-the-call-beyond-the-veil`, and CraftProfessor
requests:

```
https://storyverse.amowogbaje.com/api/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil/json
```

**Important:** regardless of which episode's slug is requested, respond with
the **entire series** — every episode, not just the one requested. This lets
one request bulk-seed (or refresh) the whole series in a single call.

## Response shape (200 OK)

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

### Field notes

| Field | Required | Notes |
|---|---|---|
| `series.title` | yes | Used as `story_series.title` on create. Ignored on subsequent re-imports (won't overwrite edits made on the CraftProfessor side — see below, or just always send the canonical title if StoryVerse should stay the source of truth). |
| `series.slug` | yes | **Stable identity for the series.** CraftProfessor stores this as `story_series.source_slug` (unique) and uses it to match on re-import. Must never change once published. |
| `series.description` | no | Free text. |
| `series.cover_image_url` | no | Absolute URL. |
| `series.url` | no | Canonical series URL on StoryVerse (not a per-episode URL). |
| `episodes[].number` | yes | Integer, 1-indexed. Becomes `stories.episode_number`. Determines ordering. |
| `episodes[].title` | no | Episode title, becomes `stories.title`. |
| `episodes[].slug` | no | Not currently used by CraftProfessor (the full `url` is the unique key instead), but useful for your own debugging/logging. |
| `episodes[].url` | yes | **Must be the full, absolute, permanent story URL.** This is stored as `stories.story_link` and is CraftProfessor's unique key for that episode — reusing the exact same URL on a later import updates the existing row instead of creating a duplicate. |
| `episodes[].content` | no, but should be present when available | Full story text. If present and different from what's already stored, CraftProfessor updates `story_text`; if absent/empty, CraftProfessor leaves any existing text alone (so you can add episodes with metadata now and backfill content later). |
| `episodes[].published_at` | no | ISO 8601. Stored as `stories.published_at`. |

### Input validation on the CraftProfessor side

The person pastes either a bare slug or a full story URL. Anything else —
a URL on the wrong host, or missing the `/stories/` path segment — is
rejected immediately with its own error message (HTTP 422), before any
request is even made to StoryVerse:

> That link doesn't match the expected StoryVerse story URL format
> (`https://storyverse.amowogbaje.com/stories/{slug}`). Please paste a
> link that looks like `https://storyverse.amowogbaje.com/stories/{slug}`.

### Error cases from StoryVerse itself

- Unknown slug → StoryVerse should respond `404` (body doesn't matter,
  but `{ "message": "Story not found." }` is a reasonable shape).
  CraftProfessor surfaces this to the person as its own distinct message:
  > No story could be found on StoryVerse for "{slug}".
- Any other non-2xx response → CraftProfessor logs the response body and
  shows a generic import-failed error. It does not retry automatically.

### Idempotency / re-imports

Calling the same endpoint again later (e.g. after publishing episode 3) is
expected and safe:

- The series is matched on `series.slug` — same slug in, same
  `story_series` row updated (title/description/cover/url refreshed).
- Each episode is matched on its exact `url` — same URL in, that
  episode's row is updated (episode number, title, published_at, and text
  if it changed) rather than duplicated. New URLs create new episodes.
