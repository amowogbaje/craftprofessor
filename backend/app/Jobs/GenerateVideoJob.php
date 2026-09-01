<?php

namespace App\Jobs;

use App\Models\StoryImagePrompt;
use App\Models\User;
use App\Services\SceneVideoGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The 'queue' side of config('ai.video_generation_mode') — see
 * VideoController for the 'sync' side. Both call the same
 * SceneVideoGenerationService, which is also what sends the outcome
 * notification, so there's nothing job-specific happening here beyond
 * "run it and log if it blew up" (the service itself already handles
 * coins/limits/notification either way).
 */
class GenerateVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420; // Veo/Agnes can take a few minutes

    public function __construct(public StoryImagePrompt $imagePrompt, public User $user)
    {
    }

    public function handle(SceneVideoGenerationService $generator): void
    {
        try {
            $generator->generate($this->imagePrompt, $this->user);
        } catch (\Throwable $e) {
            // Already logged inside the underlying services, coins already
            // refunded on failure, and the user already got a
            // SceneVideoGenerationNotification either way — nothing left
            // to do here but record it for our own debugging.
            Log::error('GenerateVideoJob: failed', [
                'story_image_prompt_id' => $this->imagePrompt->id,
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
