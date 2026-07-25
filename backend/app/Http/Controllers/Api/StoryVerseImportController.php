<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStoryVerseImportRequest;
use App\Services\StoryVerseImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/story-series/import-storyverse
 * { "url": "https://storyverse.amowogbaje.com/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil" }
 *
 * Fetches the series' full episode list from StoryVerse and bulk
 * upserts it into story_series + stories, owned by the current user.
 */
class StoryVerseImportController extends Controller
{
    public function store(StoreStoryVerseImportRequest $request, StoryVerseImportService $service): JsonResponse
    {
        try {
            $series = $service->importFromInput($request->validated('url'), $request->user());
        } catch (\Throwable $e) {
            Log::error('StoryVerseImportController: import failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to import series from StoryVerse.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Series imported from StoryVerse.',
            'series' => [
                'id' => $series->id,
                'title' => $series->title,
                'slug' => $series->slug,
                'source_slug' => $series->source_slug,
                'cover_image_url' => $series->cover_image_url,
                'episodes' => $series->stories->map(fn ($story) => [
                    'id' => $story->id,
                    'episode_number' => $story->episode_number,
                    'title' => $story->title,
                    'story_link' => $story->story_link,
                    'story_text_fetched' => !is_null($story->story_text),
                ]),
            ],
        ], 201);
    }
}
