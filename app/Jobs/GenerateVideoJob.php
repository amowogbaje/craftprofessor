<?php

namespace App\Jobs;

use App\Models\StoryImagePrompt;
use App\Models\User;
use App\Services\UsageLimitService;
use App\Services\VideoGeneratorService;
use App\Services\VideoPromptService;
use App\Services\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420; // Veo can take a few minutes

    public function __construct(public StoryImagePrompt $imagePrompt, public User $user)
    {
    }

    public function handle(
        VideoPromptService $promptService,
        VideoGeneratorService $videoService,
        UsageLimitService $limits,
    ): void {
        if (!$limits->canGenerateVideo($this->user)) {
            Log::info('GenerateVideoJob: daily/monthly video limit reached, skipping', [
                'user_id' => $this->user->id, 'story_image_prompt_id' => $this->imagePrompt->id,
            ]);
            return;
        }

        try {
            $videoPrompt = $promptService->generate($this->imagePrompt, $this->user);
            $videoService->generate($videoPrompt, $this->user);
        } catch (\Throwable $e) {
            // Already logged + refunded inside the services above.
            Log::error('GenerateVideoJob: failed', [
                'story_image_prompt_id' => $this->imagePrompt->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
