<?php

namespace App\Services;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Calls Veo on Vertex AI (image-to-video) to animate the still image behind
 * a VideoPrompt. Veo runs as a long-running operation: submit, then poll
 * fetchPredictOperation until it reports done.
 */
class VideoGeneratorService
{
    protected const POLL_INTERVAL_SECONDS = 8;
    protected const MAX_POLL_ATTEMPTS = 45; // ~6 minutes

    public function __construct(
        protected WalletService $wallet,
        protected GoogleServiceAccountAuth $auth,
    ) {
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
            'provider' => 'veo',
            'coin_cost' => $cost,
        ]);

        try {
            $operationName = $this->submit($videoPrompt->prompt, $imagePrompt->image_generated_url);
            $videoBytes = $this->poll($operationName);

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

    protected function submit(string $motionPrompt, string $sourceImageUrl): string
    {
        $config = config('ai.providers.veo');
        $token = $this->auth->getAccessToken();

        $imageBytes = base64_encode(Http::timeout(30)->get($sourceImageUrl)->body());

        $response = Http::withToken($token)
            ->timeout(60)
            ->post("{$config['url']}{$config['model']}:predictLongRunning", [
                'instances' => [[
                    'prompt' => $motionPrompt,
                    'image' => [
                        'bytesBase64Encoded' => $imageBytes,
                        'mimeType' => 'image/png',
                    ],
                ]],
                'parameters' => [
                    'sampleCount' => 1,
                ],
            ]);

        if ($response->failed()) {
            Log::error('VideoGeneratorService: submit failed', ['body' => $response->body()]);
            throw new RuntimeException('Veo submit failed: ' . $response->body());
        }

        $name = $response->json('name');
        if (!$name) {
            throw new RuntimeException('Veo did not return an operation name.');
        }

        return $name;
    }

    protected function poll(string $operationName): string
    {
        $config = config('ai.providers.veo');
        $token = $this->auth->getAccessToken();

        for ($i = 0; $i < self::MAX_POLL_ATTEMPTS; $i++) {
            sleep(self::POLL_INTERVAL_SECONDS);

            $response = Http::withToken($token)
                ->timeout(30)
                ->post("{$config['url']}{$config['model']}:fetchPredictOperation", [
                    'operationName' => $operationName,
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Veo poll failed: ' . $response->body());
            }

            $data = $response->json();

            if (data_get($data, 'done') === true) {
                if (isset($data['error'])) {
                    throw new RuntimeException('Veo operation errored: ' . json_encode($data['error']));
                }

                $videoBase64 = data_get($data, 'response.videos.0.bytesBase64Encoded')
                    ?? data_get($data, 'response.predictions.0.bytesBase64Encoded');

                if (!$videoBase64) {
                    throw new RuntimeException('Veo operation completed with no video payload.');
                }

                return base64_decode($videoBase64);
            }
        }

        throw new RuntimeException('Veo operation timed out waiting for completion.');
    }
}
