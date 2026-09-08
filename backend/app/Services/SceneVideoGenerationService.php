<?php

namespace App\Services;

use App\Exceptions\InsufficientCoinsException;
use App\Exceptions\UserGenerationLimitReached;
use App\Models\StoryImagePrompt;
use App\Models\User;
use App\Models\Video;
use App\Notifications\SceneVideoGenerationNotification;

/**
 * Generates one scene's motion-clip video: motion prompt (VideoPromptService)
 * then the actual provider call (VideoGeneratorService), with the
 * limit/coins gating and outcome notification wrapped around both.
 *
 * This exists so "how a scene's video gets generated" has exactly one
 * implementation, called from three different places depending on what
 * infrastructure is available:
 *  - php artisan story:generate-videos (CLI/manual testing)
 *  - VideoController, synchronously, when there's no queue worker running
 *    (typical shared/cPanel hosting — see config('ai.video_generation_mode'))
 *  - GenerateVideoJob, when a real queue worker is running (Redis + Supervisor)
 *
 * Throws on failure (InsufficientCoinsException, UserGenerationLimitReached,
 * or whatever the provider call itself throws) rather than swallowing
 * errors — callers decide what "failure" means for their context (an HTTP
 * error response vs. a job retry vs. a console error line).
 */
class SceneVideoGenerationService
{
    public function __construct(
        protected VideoPromptService $promptService,
        protected VideoGeneratorService $videoService,
        protected UsageLimitService $limits,
    ) {
    }

    /**
     * @throws InsufficientCoinsException
     * @throws UserGenerationLimitReached
     * @throws \Throwable from the underlying prompt/video provider calls
     */
    public function generate(StoryImagePrompt $scene, User $user, bool $notify = true): Video
    {
        if (!$this->limits->canGenerateVideo($user)) {
            throw new UserGenerationLimitReached($user->id, 'daily/monthly video limit reached');
        }

        $requiredCoins = (int) config('coins.costs.video_prompt') + (int) config('coins.costs.video_generation');
        if ($user->wallet->balance < $requiredCoins) {
            throw new InsufficientCoinsException($requiredCoins, $user->wallet->balance);
        }

        $videoPrompt = null;

        try {
            $videoPrompt = $this->promptService->generate($scene, $user);
            $video = $this->videoService->generate($videoPrompt, $user);

            if (!$video || !$video->video_url) {
                throw new \RuntimeException('Video provider returned no video.');
            }

            if ($notify) {
                $user->notify(new SceneVideoGenerationNotification($scene, succeeded: true, video: $video));
            }

            return $video;
        } catch (\Throwable $e) {
            // VideoPromptService already records failures at its own stage.
            // This covers the other case: the prompt stage succeeded (so
            // $videoPrompt has no error on it) but the video-provider stage
            // (VideoGeneratorService, or the "no video" RuntimeException
            // above) is what actually failed — without this, that
            // VideoPrompt row would sit there looking successful right up
            // until StoryImagePrompt::clearVideoAttempt() removes it on the
            // next retry.
            if ($videoPrompt && is_null($videoPrompt->fresh()?->last_generation_error)) {
                $videoPrompt->update([
                    'last_generation_error' => \Illuminate\Support\Str::limit($e->getMessage(), 2000),
                    'generation_attempts' => $videoPrompt->generation_attempts + 1,
                ]);
            }

            if ($notify) {
                $user->notify(new SceneVideoGenerationNotification($scene, succeeded: false, errorMessage: $e->getMessage()));
            }

            throw $e;
        }
    }
}
