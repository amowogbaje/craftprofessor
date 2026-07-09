<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Story;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StoryController extends Controller
{
    /**
     * POST /api/stories
     * { text: string, title?: string }
     *
     * Creates a standalone (non-series) story owned by the current user from
     * pasted text. Scheduler 2 (story:generate-image-prompts) will pick it
     * up automatically since Story::scopeReadyForPrompts() matches on
     * user_supplied_text too.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'text' => ['required', 'string', 'min:50'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $story = Story::create([
            'user_id' => $request->user()->id,
            'user_supplied_text' => $request->input('text'),
            'story_text' => $request->input('text'), // treat identically to a fetched story
        ]);

        return response()->json(['message' => 'Story submitted for processing.', 'story' => $story], 201);
    }

    /** GET /api/stories */
    public function index(Request $request): JsonResponse
    {
        $stories = $request->user()->stories()
            ->with('series:id,title,slug')
            ->withCount('imagePrompts')
            ->latest()
            ->paginate(20);

        return response()->json($stories);
    }

    /** GET /api/story-series (list, distinct from POST which creates) */
    public function series(Request $request): JsonResponse
    {
        $series = $request->user()->storySeries()
            ->withCount('stories')
            ->latest()
            ->paginate(20);

        return response()->json($series);
    }
}
