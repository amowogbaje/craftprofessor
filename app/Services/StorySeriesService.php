<?php

namespace App\Services;

use App\Models\Story;
use App\Models\StorySeries;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single place that turns "an ordered list of medium links" into a
 * StorySeries + its episode Story rows. Used by both
 * `php artisan story:create-series` and the POST /api/story-series
 * endpoint so the two stay in sync.
 */
class StorySeriesService
{
    /**
     * @param  array<int, string>  $links  ordered, episode 1 first
     */
    public function createLinkedSeries(array $links, ?string $title = null, ?string $description = null, ?User $user = null): StorySeries
    {
        $title ??= 'Series ' . now()->format('Y-m-d H:i:s');

        $series = StorySeries::create([
            'user_id' => $user?->id,
            'title' => $title,
            'description' => $description,
        ]);

        Log::info('StorySeriesService: creating linked series', [
            'series_id' => $series->id,
            'user_id' => $user?->id,
            'title' => $title,
            'link_count' => count($links),
        ]);

        foreach (array_values($links) as $index => $link) {
            $link = trim($link);
            if ($link === '') {
                continue;
            }

            $story = Story::firstOrCreate(
                ['medium_link' => $link],
                ['series_id' => $series->id, 'user_id' => $user?->id, 'episode_number' => $index + 1]
            );

            // Already existed (e.g. as a standalone story, or re-submitted) —
            // attach/renumber it into this series rather than duplicating.
            if ($story->series_id !== $series->id) {
                $story->update(['series_id' => $series->id, 'user_id' => $user?->id, 'episode_number' => $index + 1]);
            }
        }

        return $series->fresh('stories');
    }
}

