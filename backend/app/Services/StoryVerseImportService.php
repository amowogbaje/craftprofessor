<?php

namespace App\Services;

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
 *   GET {STORYVERSE_BASE_URL}/stories/{slug}/json
 *
 * The person only ever hands us the public story URL (or its slug) —
 * e.g. https://storyverse.amowogbaje.com/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil.
 * We hit the sibling /json endpoint for that slug, which is expected to
 * return the *entire* series (every episode, not just the one requested)
 * so a single import call seeds/updates the whole series in one shot.
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
 */
class StoryVerseImportService
{
    public function importFromInput(string $input, ?User $user = null): StorySeries
    {
        $slug = $this->extractSlug($input);
        $payload = $this->fetch($slug);

        return $this->upsert($payload, $user);
    }

    /** Accepts either a bare slug or a full StoryVerse story URL. */
    protected function extractSlug(string $input): string
    {
        $input = trim($input);

        if (!Str::startsWith($input, ['http://', 'https://'])) {
            return trim($input, '/');
        }

        $path = parse_url($input, PHP_URL_PATH) ?? '';

        return trim((string) Str::afterLast(rtrim($path, '/'), '/'));
    }

    protected function fetch(string $slug): array
    {
        if ($slug === '') {
            throw new RuntimeException('Could not determine a StoryVerse slug from the given input.');
        }

        $base = rtrim((string) config('services.storyverse.base_url'), '/');
        $url = "{$base}/stories/{$slug}/json";

        Log::info('StoryVerseImportService: fetching series JSON', ['slug' => $slug, 'url' => $url]);

        $response = Http::timeout(20)->acceptJson()->get($url);

        if ($response->failed()) {
            Log::error('StoryVerseImportService: fetch failed', [
                'slug' => $slug,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 1000),
            ]);

            throw new RuntimeException("StoryVerse import failed for slug [{$slug}]: HTTP {$response->status()}.");
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
