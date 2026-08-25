<?php

namespace App\Services;

use App\Ai\Contracts\VideoProviderContract;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoPrompt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Animates the still image behind a VideoPrompt into a short video clip.
 * The actual provider (Veo on Vertex AI, Agnes AI, ...) is injected via
 * VideoProviderContract — see AppServiceProvider's binding and
 * config('ai.default_video_provider') — so this service stays provider
 * agnostic, the same way ImageGeneratorService is for images.
 */
class VideoGeneratorService
{
    public function __construct(
        protected WalletService $wallet,
        protected VideoProviderContract $videoProvider,
    ) {
    }

    /** Which provider is currently active (e.g. "veo", "agnes") — for logging/CLI output. */
    public function providerName(): string
    {
        return $this->videoProvider->name();
    }

    public function generate(VideoPrompt $videoPrompt, User $user): ?Video
    {
        $imagePrompt = $videoPrompt->imagePrompt;

        if (empty($imagePrompt->image_generated_url)) {
            throw new RuntimeException('Source image is missing.');
        }

        $cost = (int) config('coins.costs.video_generation');
        $this->wallet->debit($user, $cost, 'video_generation', $videoPrompt);

        $video = Video::create([
            'user_id' => $user->id,
            'video_prompt_id' => $videoPrompt->id,
            'story_image_prompt_id' => $imagePrompt->id,
            'provider' => $this->videoProvider->name(),
            'coin_cost' => $cost,
        ]);

        try {
            $videoBytes = $this->videoProvider->generate($videoPrompt->prompt, $imagePrompt->image_generated_url);

            $path = "story-videos/{$imagePrompt->id}/{$video->id}-" . Str::random(8) . '.mp4';
            Storage::disk('public')->put($path, $videoBytes);
            $url = Storage::disk('public')->url($path);

            $video->update([
                'video_url' => $url,
                'generated_at' => now(),
                'last_generation_error' => null,
            ]);

            Log::info('VideoGeneratorService: video generated', ['video_id' => $video->id]);

            return $video;
        } catch (\Throwable $e) {
            $video->update([
                'last_generation_error' => Str::limit($e->getMessage(), 2000),
                'generation_attempts' => $video->generation_attempts + 1,
            ]);

            $this->wallet->refund($user, $cost, 'video_generation', $video, ['error' => $e->getMessage()]);

            Log::error('VideoGeneratorService: generation failed, refunded', [
                'video_id' => $video->id, 'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
