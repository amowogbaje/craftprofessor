<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientCoinsException;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateVideoJob;
use App\Models\StoryImagePrompt;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoController extends Controller
{
    /**
     * POST /api/story-image-prompts/{imagePrompt}/video
     * Queues motion-prompt + video-provider generation for one of the
     * user's own published/draft scene images. Coins are actually charged
     * inside the job (so a slow queue doesn't block the request), but we
     * do a cheap up-front affordability check here to fail fast with a
     * clear message.
     */
    public function store(Request $request, StoryImagePrompt $imagePrompt): JsonResponse
    {
        $user = $request->user();
        abort_if($imagePrompt->user_id !== $user->id, 403, 'Not your image.');
        abort_if(empty($imagePrompt->image_generated_url), 422, 'Image has not been generated yet.');
        abort_if($imagePrompt->videoPrompt()->exists(), 422, 'A video has already been requested for this image — use the regenerate endpoint instead.');

        $requiredCoins = (int) config('coins.costs.video_prompt') + (int) config('coins.costs.video_generation');
        if ($user->wallet->balance < $requiredCoins) {
            throw new InsufficientCoinsException($requiredCoins, $user->wallet->balance);
        }

        GenerateVideoJob::dispatch($imagePrompt, $user)->onQueue('videos');

        return response()->json([
            'message' => 'Video generation queued. This can take a few minutes.',
        ], 202);
    }

    /**
     * POST /api/story-image-prompts/{imagePrompt}/video/regenerate
     * Clears any previous motion-prompt/video attempt for this scene
     * (whichever provider generated it, and whether it succeeded or
     * failed) and queues a fresh one — the same pipeline as store(), just
     * without the "already requested" guard blocking it. Coins are
     * charged again, same as any other generation.
     */
    public function regenerate(Request $request, StoryImagePrompt $imagePrompt): JsonResponse
    {
        $user = $request->user();
        abort_if($imagePrompt->user_id !== $user->id, 403, 'Not your image.');
        abort_if(empty($imagePrompt->image_generated_url), 422, 'Image has not been generated yet.');

        $existingPrompt = $imagePrompt->videoPrompt;

        if ($existingPrompt) {
            Video::where('video_prompt_id', $existingPrompt->id)->delete();
            $existingPrompt->delete();
        }

        $requiredCoins = (int) config('coins.costs.video_prompt') + (int) config('coins.costs.video_generation');
        if ($user->wallet->balance < $requiredCoins) {
            throw new InsufficientCoinsException($requiredCoins, $user->wallet->balance);
        }

        GenerateVideoJob::dispatch($imagePrompt->refresh(), $user)->onQueue('videos');

        return response()->json([
            'message' => 'Previous video cleared — regeneration queued.',
        ], 202);
    }
}
