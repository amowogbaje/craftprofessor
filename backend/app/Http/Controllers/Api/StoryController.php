<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Story;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    /**
     * PUT /api/stories/{story}
     * { pinterest_board_id?: number|null, pinterest_daily_pin_limit?: number|null }
     *
     * Only these two Pinterest overrides are editable here — everything
     * else about a story (title, text, etc.) is set once at creation/
     * import and isn't meant to be edited after the fact. See
     * Story::pinterestBoard() / effectivePinterestDailyPinLimit() for how
     * these get applied; PinterestBoardSelectionService and
     * PostPinterestPins for where.
     */
    public function update(Request $request, Story $story): JsonResponse
    {
        abort_if($story->user_id !== $request->user()->id, 403, 'Not your story.');

        $validator = Validator::make($request->all(), [
            'pinterest_board_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('pinterest_boards', 'id')->where('user_id', $request->user()->id),
            ],
            'pinterest_daily_pin_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $story->update($validator->validated());

        return response()->json(['message' => 'Story updated.', 'story' => $story->fresh()]);
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
            'pinterestBoard:id,name,is_active',
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
            // The user's full board list, for a "choose a board for this
            // story" picker — same source PinterestBoardsPage already
            // uses, just scoped here for convenience so the story page
            // doesn't need a second request to build that picker.
            'pinterest_boards' => $request->user()->socialAccount('pinterest')
                ?->boards()->active()->get(['id', 'name'])
                ?? collect(),
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
