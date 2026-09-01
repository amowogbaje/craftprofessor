<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientCoinsException;
use App\Exceptions\UserGenerationLimitReached;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateVideoJob;
use App\Models\StoryImagePrompt;
use App\Models\Video;
use App\Services\SceneVideoGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VideoController extends Controller
{
    /**
     * POST /api/story-image-prompts/{imagePrompt}/video
     *
     * Two ways this runs, picked by config('ai.video_generation_mode'):
     *  - 'queue' (default): dispatches GenerateVideoJob and returns
     *    immediately. Needs a real queue worker running (Redis + Supervisor
     *    or similar) — otherwise this just queues up and nothing happens.
     *  - 'sync': calls SceneVideoGenerationService directly and the
     *    request blocks until the provider call finishes (can take a few
     *    minutes). For hosting with no persistent worker process, e.g.
     *    plain shared/cPanel hosting.
     * Either way, a SceneVideoGenerationNotification fires on success or
     * failure — see App\Notifications — so the outcome isn't only visible
     * to whoever's still watching this request.
     */
    public function store(Request $request, StoryImagePrompt $imagePrompt, SceneVideoGenerationService $generator): JsonResponse
    {
        $user = $request->user();
        abort_if($imagePrompt->user_id !== $user->id, 403, 'Not your image.');
        abort_if(empty($imagePrompt->image_generated_url), 422, 'Image has not been generated yet.');
        abort_if($imagePrompt->videoPrompt()->exists(), 422, 'A video has already been requested for this image — use the regenerate endpoint instead.');

        return $this->dispatchOrRunSync($imagePrompt, $user, $generator, 'Video generation queued. This can take a few minutes.');
    }

    /**
     * POST /api/story-image-prompts/{imagePrompt}/video/regenerate
     * Clears any previous motion-prompt/video attempt for this scene
     * (whichever provider generated it, and whether it succeeded or
     * failed), then runs the same store() pipeline without the "already
     * requested" guard blocking it. Coins are charged again, same as any
     * other generation.
     */
    public function regenerate(Request $request, StoryImagePrompt $imagePrompt, SceneVideoGenerationService $generator): JsonResponse
    {
        $user = $request->user();
        abort_if($imagePrompt->user_id !== $user->id, 403, 'Not your image.');
        abort_if(empty($imagePrompt->image_generated_url), 422, 'Image has not been generated yet.');

        $existingPrompt = $imagePrompt->videoPrompt;

        if ($existingPrompt) {
            Video::where('video_prompt_id', $existingPrompt->id)->delete();
            $existingPrompt->delete();
        }

        return $this->dispatchOrRunSync($imagePrompt->refresh(), $user, $generator, 'Previous video cleared — regeneration queued.');
    }

    protected function dispatchOrRunSync(
        StoryImagePrompt $imagePrompt,
        $user,
        SceneVideoGenerationService $generator,
        string $queuedMessage,
    ): JsonResponse {
        if (config('ai.video_generation_mode') === 'sync') {
            try {
                $video = $generator->generate($imagePrompt, $user);

                return response()->json([
                    'message' => 'Video generated.',
                    'data' => $video,
                ], 201);
            } catch (InsufficientCoinsException $e) {
                throw $e; // has its own render() -> 402 with the right payload
            } catch (UserGenerationLimitReached $e) {
                return response()->json(['message' => 'Daily/monthly video limit reached.'], 429);
            } catch (\Throwable $e) {
                Log::error('VideoController: sync generation failed', [
                    'story_image_prompt_id' => $imagePrompt->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['message' => 'Video generation failed: ' . $e->getMessage()], 502);
            }
        }

        // Cheap up-front affordability check so a doomed request fails
        // fast rather than silently sitting in the queue — the real
        // charge/limit check still happens inside the job either way.
        $requiredCoins = (int) config('coins.costs.video_prompt') + (int) config('coins.costs.video_generation');
        if ($user->wallet->balance < $requiredCoins) {
            throw new InsufficientCoinsException($requiredCoins, $user->wallet->balance);
        }

        GenerateVideoJob::dispatch($imagePrompt, $user)->onQueue('videos');

        return response()->json(['message' => $queuedMessage], 202);
    }
}
