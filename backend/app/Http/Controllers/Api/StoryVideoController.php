<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AssembleStoryVideoJob;
use App\Models\Story;
use App\Models\StoryVideo;
use App\Services\SocialPlatforms\PinterestPlatform;
use App\Services\SocialPlatforms\SocialPlatformManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    /**
     * POST /api/stories/{story}/video/publish-pinterest
     * Posts the assembled, narrated-and-captioned StoryVideo (the "mixed
     * copy" — see StoryVideoAssemblyService) as a Pinterest video pin.
     * Deliberately never posts a raw per-scene asset; only the finished
     * story video. Goes through the same SocialPlatformManager/SocialPost
     * ledger every other platform post in this app uses (per-account
     * token, retry/failure tracking, dedup) rather than talking to
     * PinterestService directly — see PinterestPlatform::publishStoryVideo().
     * Runs synchronously (Pinterest's own upload+processing poll can take
     * up to ~a minute).
     */
    public function publishToPinterest(Request $request, Story $story, SocialPlatformManager $platforms): JsonResponse
    {
        abort_if($story->user_id !== $request->user()->id, 403, 'Not your story.');

        $storyVideo = $story->video;

        if (!$storyVideo) {
            return response()->json(['message' => 'No assembled video for this story yet.'], 404);
        }

        if ($storyVideo->status !== StoryVideo::STATUS_READY) {
            return response()->json(['message' => 'Video is not ready yet.'], 422);
        }

        try {
            /** @var PinterestPlatform $pinterest */
            $pinterest = $platforms->forUser($request->user()->id, 'pinterest');
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Connect a Pinterest account first.'], 422);
        }

        $result = $pinterest->publishStoryVideo($storyVideo);

        if ($result->success) {
            $storyVideo->update([
                'posted_to_pinterest' => true,
                'pinterest_posted_at' => now(),
                'pinterest_pin_id' => $result->externalPostId,
                'last_pinterest_error' => null,
            ]);

            return response()->json(['message' => 'Posted to Pinterest.', 'data' => $storyVideo->fresh()]);
        }

        $storyVideo->update(['last_pinterest_error' => Str::limit($result->error ?? 'Unknown failure.', 2000)]);

        return response()->json(['message' => 'Pinterest publish failed: ' . $result->error], 502);
    }
}
