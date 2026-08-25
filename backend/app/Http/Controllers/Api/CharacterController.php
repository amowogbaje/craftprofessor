<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Story;
use App\Models\StoryImagePrompt;
use App\Models\StorySeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CharacterController extends Controller
{
    /**
     * GET /api/stories/{story:slug}/characters
     * Characters for a standalone story. If this story is actually part of
     * a series, characters belong to the series instead (see
     * Story::knownCharacters()) — rather than 404, this responds with the
     * series-wide list plus a `redirect_to` hint so the frontend can send
     * the user to the canonical /series/{slug}/characters URL.
     */
    public function forStory(Request $request, Story $story): JsonResponse
    {
        $this->authorizeOwner($request, $story->user_id);

        return response()->json([
            'scope' => $story->isPartOfSeries() ? 'series' : 'story',
            'redirect_to' => $story->isPartOfSeries() ? "/series/{$story->series->slug}/characters" : null,
            'title' => $story->isPartOfSeries() ? $story->series->title : ($story->title ?: 'This story'),
            'data' => $story->knownCharacters()->orderBy('name')->get(),
        ]);
    }

    /** GET /api/series/{series:slug}/characters — every character across every episode of the series. */
    public function forSeries(Request $request, StorySeries $series): JsonResponse
    {
        $this->authorizeOwner($request, $series->user_id);

        return response()->json([
            'scope' => 'series',
            'redirect_to' => null,
            'title' => $series->title,
            'data' => $series->characters()->orderBy('name')->get(),
        ]);
    }

    /**
     * GET /api/characters/{character}
     * One character's own detail: every scene they appear in (across every
     * episode if series-owned) plus that scene's video, if generated.
     */
    public function show(Request $request, Character $character): JsonResponse
    {
        $this->authorizeOwner($request, $character->user_id);

        $scenes = StoryImagePrompt::query()
            ->whereJsonContains('main_character_ids', $character->id)
            ->with(['video', 'story:id,title,slug,series_id'])
            ->orderByDesc('generated_at')
            ->get();

        return response()->json([
            'data' => $character,
            'scenes' => $scenes,
        ]);
    }

    protected function authorizeOwner(Request $request, ?int $ownerId): void
    {
        abort_if($request->user()->id !== $ownerId, 403, 'Not yours.');
    }
}
