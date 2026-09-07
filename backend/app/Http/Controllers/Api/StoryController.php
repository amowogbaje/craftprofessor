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
            'story_link' => ['nullable', 'string', 'url', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $story = Story::create([
            'user_id' => $request->user()->id,
            'user_supplied_text' => $request->input('text'),
            'story_text' => $request->input('text'), // treat identically to a fetched story
            'story_link' => $request->input('story_link'),
        ]);

        return response()->json(['message' => 'Story submitted for processing.', 'story' => $story], 201);
    }

    /** GET /api/stories */
    public function index(Request $request): JsonResponse
    {
        $stories = $request->user()->stories()
            ->with('series:id,title,slug', 'video')
            ->withCount('imagePrompts')
            ->latest()
            ->paginate(20);

        return response()->json($stories);
    }

    /**
     * GET /api/stories/{story:slug}
     * Story detail: ordered scenes (with per-scene video, if generated),
     * the assembled full-story video (if any), and the character/
     * environment/prop pool this story draws on (see Story::knownCharacters()
     * et al — series-wide for episodes, story-only for standalone).
     */
    public function show(Request $request, Story $story): JsonResponse
    {
        $this->authorizeOwner($request, $story->user_id);

        $story->load([
            'series:id,title,slug',
            'video',
            'imagePrompts' => fn ($q) => $q->ordered()->with('video'),
        ]);

        return response()->json([
            'data' => $story,
            'characters' => $story->knownCharacters()->get(['id', 'name', 'img_url', 'voice', 'story_id', 'series_id']),
            'environments' => $story->knownEnvironments()->get(['id', 'name', 'img_url', 'story_id', 'series_id']),
            'props' => $story->knownProps()->get(['id', 'name', 'img_url', 'story_id', 'series_id']),
            'characters_scope' => $story->isPartOfSeries() ? 'series' : 'story',
            'characters_path' => $story->isPartOfSeries()
                ? "/series/{$story->series->slug}/characters"
                : "/stories/{$story->slug}/characters",
        ]);
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

    protected function authorizeOwner(Request $request, ?int $ownerId): void
    {
        abort_if($request->user()->id !== $ownerId, 403, 'Not your story.');
    }
}
