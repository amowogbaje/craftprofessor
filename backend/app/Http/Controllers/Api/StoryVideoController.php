<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AssembleStoryVideoJob;
use App\Models\Story;
use App\Models\StoryVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryVideoController extends Controller
{
    /**
     * POST /api/stories/{story}/video
     * Queues assembly of this story's scenes (+ narration audio, where
     * available) into one final video. Safe to call again while a previous
     * attempt is 'failed' or to regenerate a 'ready' one — StoryVideo is
     * one row per story, overwritten on each successful run.
     */
    public function store(Request $request, Story $story): JsonResponse
    {
        abort_if($story->user_id !== $request->user()->id, 403, 'Not your story.');
        abort_if(!$story->imagePrompts()->whereNotNull('image_generated_url')->exists(), 422, 'No generated scenes yet.');

        $storyVideo = StoryVideo::firstOrCreate(
            ['story_id' => $story->id],
            ['user_id' => $story->user_id, 'status' => StoryVideo::STATUS_PENDING]
        );

        if ($storyVideo->status === StoryVideo::STATUS_PROCESSING) {
            return response()->json(['message' => 'Video assembly already in progress.', 'data' => $storyVideo], 202);
        }

        $storyVideo->update(['status' => StoryVideo::STATUS_PENDING, 'last_generation_error' => null]);

        AssembleStoryVideoJob::dispatch($story)->onQueue('videos');

        return response()->json([
            'message' => 'Video assembly queued.',
            'data' => $storyVideo->fresh(),
        ], 202);
    }

    /** GET /api/stories/{story}/video — poll status while assembly runs. */
    public function show(Request $request, Story $story): JsonResponse
    {
        abort_if($story->user_id !== $request->user()->id, 403, 'Not your story.');

        $storyVideo = $story->video;

        if (!$storyVideo) {
            return response()->json(['message' => 'No video has been requested for this story yet.'], 404);
        }

        return response()->json(['data' => $storyVideo]);
    }
}
