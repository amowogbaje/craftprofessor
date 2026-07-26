<?php

namespace App\Services;

use App\Exceptions\InvalidStoryVerseUrlException;
use App\Exceptions\StoryVerseStoryNotFoundException;
use App\Models\Story;
use App\Models\StorySeries;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports a StoryVerse story (and every other episode in its series) via
 * StoryVerse's read-only JSON endpoint:
 *
 *   GET {STORYVERSE_BASE_URL}/api/stories/{slug}/json
 *
 * The person only ever hands us the public story URL (or its slug) —
 * e.g. https://storyverse.amowogbaje.com/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil.
 * We hit the /api/stories/{slug}/json endpoint for that slug, which is
 * expected to return the *entire* series (every episode, not just the one
 * requested) so a single import call seeds/updates the whole series in one
 * shot.
 *
 * Expected response shape — see docs/storyverse-import-contract.md for the
 * full spec:
 *
 * {
 *   "series": {
 *     "title": "Shadow of the Sentinel",
 *     "slug": "shadow-of-the-sentinel",
 *     "description": "...",
 *     "cover_image_url": "https://.../cover.jpg",
 *     "url": "https://storyverse.amowogbaje.com/series/shadow-of-the-sentinel"
 *   },
 *   "episodes": [
 *     {
 *       "number": 1,
 *       "title": "The Awakening",
 *       "slug": "shadow-of-the-sentinel-1-the-awakening",
 *       "url": "https://storyverse.amowogbaje.com/stories/shadow-of-the-sentinel-1-the-awakening",
 *       "content": "Full episode text...",
 *       "published_at": "2026-07-03T08:00:00Z"
 *     }
 *   ]
 * }
 *
 * Re-importing the same series (e.g. after StoryVerse publishes a new
 * episode) is safe: series are matched on (source, source_slug) and
 * episodes are matched on their unique story_link, so existing rows are
 * updated in place rather than duplicated.
 *
 * Throws two distinct, catchable exceptions so callers can show a
 * meaningful message rather than a generic failure:
 *  - InvalidStoryVerseUrlException: the pasted input isn't a bare slug and
 *    doesn't look like https://storyverse.amowogbaje.com/stories/{slug}.
 *  - StoryVerseStoryNotFoundException: the input was a well-formed
 *    slug/URL, but StoryVerse returned 404 for it.
 */
class StoryVerseImportService
{
    public function importFromInput(string $input, ?User $user = null): StorySeries
    {
        $slug = $this->extractSlug($input);
        $payload = $this->fetch($slug);

        return $this->upsert($payload, $user);
    }

    /**
     * Accepts either a bare slug (e.g. "shadow-of-the-sentinel-1-the-awakening")
     * or a full StoryVerse story URL
     * (https://storyverse.amowogbaje.com/stories/{slug}). Anything else —
     * a URL on the wrong host, or missing the /stories/ path — is rejected
     * with a specific, actionable error rather than silently guessing.
     */
    protected function extractSlug(string $input): string
    {
        $input = trim($input);
        $expectedHost = parse_url((string) config('services.storyverse.reader_base_url'), PHP_URL_HOST);
        $example = "https://{$expectedHost}/stories/{slug}";

        if (!Str::startsWith($input, ['http://', 'https://'])) {
            $slug = trim($input, '/');

            if ($slug === '') {
                throw new InvalidStoryVerseUrlException(
                    "That doesn't look like a StoryVerse story link or slug. Paste either the full story URL ({$example}) or just its slug."
                );
            }

            return $slug;
        }

        $host = parse_url($input, PHP_URL_HOST);
        $path = rtrim(parse_url($input, PHP_URL_PATH) ?? '', '/');

        if (!$host || !$expectedHost || strcasecmp($host, $expectedHost) !== 0 || !Str::startsWith($path, '/stories/')) {
            throw new InvalidStoryVerseUrlException(
                "That link doesn't match the expected StoryVerse story URL format ({$example}). Please paste a link that looks like {$example}."
            );
        }

        $slug = trim((string) Str::afterLast($path, '/'));

        if ($slug === '' || $slug === 'stories') {
            throw new InvalidStoryVerseUrlException(
                "Couldn't find a story slug in that link. Please paste a link that looks like {$example}."
            );
        }

        return $slug;
    }

    protected function fetch(string $slug): array
    {
        $base = rtrim((string) config('services.storyverse.base_url'), '/');
        $url = "{$base}/api/stories/{$slug}/json";

        Log::info('StoryVerseImportService: fetching series JSON', ['slug' => $slug, 'url' => $url]);

        $response = Http::timeout(20)->acceptJson()->get($url);

        if ($response->status() === 404) {
            Log::warning('StoryVerseImportService: story not found', ['slug' => $slug, 'url' => $url]);

            throw new StoryVerseStoryNotFoundException("No story could be found on StoryVerse for \"{$slug}\".");
        }

        if ($response->failed()) {
            Log::error('StoryVerseImportService: fetch failed', [
                'slug' => $slug,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 1000),
            ]);

            throw new RuntimeException("StoryVerse import failed for slug [{$slug}]: HTTP {$response->status()}.");
        }

        if (!Str::contains($response->header('Content-Type') ?? '', 'json')) {
            // A 200 with an HTML body almost always means the request hit
            // a frontend SPA catch-all instead of the actual API route —
            // e.g. wrong host, or the endpoint isn't registered/deployed
            // yet on StoryVerse's side.
            Log::error('StoryVerseImportService: non-JSON response', [
                'slug' => $slug,
                'url' => $url,
                'content_type' => $response->header('Content-Type'),
                'body' => Str::limit($response->body(), 500),
            ]);

            throw new RuntimeException(
                "StoryVerse returned a webpage instead of JSON for [{$slug}] — the /api/stories/{slug}/json endpoint may not be deployed yet, or {$url} is hitting the wrong host."
            );
        }

        $data = $response->json();

        if (!is_array($data) || empty($data['series']['slug']) || !isset($data['episodes']) || !is_array($data['episodes'])) {
            throw new RuntimeException("StoryVerse response for slug [{$slug}] is missing required series/episodes fields.");
        }

        return $data;
    }

    protected function upsert(array $data, ?User $user): StorySeries
    {
        $seriesData = $data['series'];

        return DB::transaction(function () use ($seriesData, $data, $user) {
            /** @var StorySeries $series */
            $series = StorySeries::updateOrCreate(
                ['source' => 'storyverse', 'source_slug' => $seriesData['slug']],
                [
                    'user_id' => $user?->id,
                    'title' => $seriesData['title'] ?? $seriesData['slug'],
                    'description' => $seriesData['description'] ?? null,
                    'cover_image_url' => $seriesData['cover_image_url'] ?? null,
                    'external_url' => $seriesData['url'] ?? null,
                ]
            );

            $imported = 0;

            foreach ($data['episodes'] as $episode) {
                if (empty($episode['url']) || !isset($episode['number'])) {
                    Log::warning('StoryVerseImportService: skipping episode missing url/number', ['episode' => $episode]);
                    continue;
                }

                /** @var Story $story */
                $story = Story::firstOrNew(['story_link' => $episode['url']]);
                $isNew = !$story->exists;

                $story->fill([
                    'series_id' => $series->id,
                    'user_id' => $user?->id ?? $story->user_id,
                    'episode_number' => $episode['number'],
                    'title' => $episode['title'] ?? $story->title,
                    'source' => 'storyverse',
                    'published_at' => $episode['published_at'] ?? $story->published_at,
                ]);

                // Only touch story_text (and re-arm prompt generation) when the
                // content actually changed, so re-imports don't wipe out image
                // prompts already generated against the previous text.
                $content = $episode['content'] ?? null;
                if ($content && $story->story_text !== $content) {
                    $story->story_text = $content;
                    if ($isNew) {
                        $story->prompt_generated = false;
                    }
                }

                $story->save();
                $imported++;
            }

            Log::info('StoryVerseImportService: import complete', [
                'series_id' => $series->id,
                'source_slug' => $series->source_slug,
                'episodes_imported' => $imported,
            ]);

            return $series->fresh('stories');
        });
    }
}
