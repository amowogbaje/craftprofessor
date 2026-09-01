<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStorySeriesRequest;
use App\Models\StorySeries;
use App\Services\StorySeriesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/story-series
 *
 * Accepts an array of links and treats ALL of them as linked episodes of
 * one series (in the order given) — this endpoint has no "standalone"
 * mode. For unrelated one-off stories, seed/insert into `stories` directly
 * with series_id left null instead.
 *
 * Request body:
 * {
 *   "links": ["https://medium.com/@you/ep-1", "https://medium.com/@you/ep-2"],
 *   "title": "Optional series title",
 *   "description": "Optional description"
 * }
 */
class StorySeriesController extends Controller
{
    public function store(StoreStorySeriesRequest $request, StorySeriesService $service): JsonResponse
    {
        $validated = $request->validated();

        Log::info('StorySeriesController: received series creation request', [
            'link_count' => count($validated['links']),
        ]);

        try {
            $series = $service->createLinkedSeries(
                $validated['links'],
                $validated['title'] ?? null,
                $validated['description'] ?? null,
                $request->user(),
            );
        } catch (\Throwable $e) {
            Log::error('StorySeriesController: failed to create series', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to create series.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Series created.',
            'series' => [
                'id' => $series->id,
                'title' => $series->title,
                'slug' => $series->slug,
                'description' => $series->description,
                'episodes' => $series->stories->map(fn ($story) => [
                    'id' => $story->id,
                    'episode_number' => $story->episode_number,
                    'story_link' => $story->story_link,
                    'story_text_fetched' => !is_null($story->story_text),
                ]),
            ],
        ], 201);
    }

    /**
     * GET /api/series/{series:slug}
     * Series detail: characters/environments/props shared across every
     * episode (see StorySeries::characters() et al), plus the ordered
     * list of episode stories — each one links to its own /stories/{slug}
     * detail page from the frontend.
     */
    public function show(Request $request, StorySeries $series): JsonResponse
    {
        abort_if($request->user()->id !== $series->user_id, 403, 'Not your series.');

        $series->loadCount('stories');

        return response()->json([
            'data' => $series,
            'characters' => $series->characters()->orderBy('name')->get(['id', 'name', 'img_url', 'story_id', 'series_id']),
            'environments' => $series->environments()->orderBy('name')->get(['id', 'name', 'img_url', 'story_id', 'series_id']),
            'props' => $series->props()->orderBy('name')->get(['id', 'name', 'img_url', 'story_id', 'series_id']),
            'episodes' => $series->stories()
                ->orderBy('episode_number')
                ->withCount('imagePrompts')
                ->get(['id', 'slug', 'title', 'episode_number', 'prompt_generated', 'created_at']),
        ]);
    }
}
