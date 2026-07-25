<?php

namespace App\Services;

use App\Ai\Agents\VideoPromptAgent;
use App\Exceptions\InsufficientCoinsException;
use App\Models\StoryImagePrompt;
use App\Models\User;
use App\Models\VideoPrompt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoPromptService
{
    public function __construct(protected WalletService $wallet)
    {
    }

    /**
     * @throws InsufficientCoinsException
     */
    public function generate(StoryImagePrompt $imagePrompt, User $user): VideoPrompt
    {
        if (empty($imagePrompt->image_generated_url)) {
            throw new \RuntimeException('Cannot create a video prompt before the source image exists.');
        }

        $cost = (int) config('coins.costs.video_prompt');

        // Charge first — refund automatically if generation fails below.
        $transaction = $this->wallet->debit($user, $cost, 'video_prompt', $imagePrompt);

        $videoPrompt = VideoPrompt::create([
            'user_id' => $user->id,
            'story_image_prompt_id' => $imagePrompt->id,
            'prompt' => '',
            'coin_cost' => $cost,
        ]);

        try {
            $text = trim((new VideoPromptAgent($imagePrompt))->prompt('Write the motion prompt now.'));

            $videoPrompt->update([
                'prompt' => $text,
                'generated_at' => now(),
                'last_generation_error' => null,
            ]);

            Log::info('VideoPromptService: motion prompt generated', [
                'video_prompt_id' => $videoPrompt->id,
                'story_image_prompt_id' => $imagePrompt->id,
            ]);

            return $videoPrompt;
        } catch (\Throwable $e) {
            $videoPrompt->update([
                'last_generation_error' => Str::limit($e->getMessage(), 2000),
                'generation_attempts' => $videoPrompt->generation_attempts + 1,
            ]);

            $this->wallet->refund($user, $cost, 'video_prompt', $imagePrompt, [
                'video_prompt_id' => $videoPrompt->id,
                'error' => $e->getMessage(),
            ]);

            Log::error('VideoPromptService: generation failed, refunded', [
                'video_prompt_id' => $videoPrompt->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
