<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\VideoProviderContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Agnes AI video provider — image-to-video via Agnes Video V2.0, an
 * asynchronous task API: POST to create a task, then GET to poll it until
 * a status of "completed" (or "failed") comes back.
 *
 * NOTE ON VERIFICATION: same caveat as AgnesAiImageProvider — built from
 * Agnes' published docs, not exercised against a live account. Different
 * third-party write-ups of this same API disagree on small details (the
 * poll id field is called `video_id` in some places, `id`/`task_id` in
 * others; the completed URL shows up as either `video_url` or
 * `metadata.url`). This implementation defensively checks every variant
 * it found documented — worth confirming against your own dashboard docs
 * once you have a key, and tightening once you see a real response.
 */
class AgnesAiVideoProvider implements VideoProviderContract
{
    protected const POLL_INTERVAL_SECONDS = 8;
    protected const MAX_POLL_ATTEMPTS = 45; // ~6 minutes, matches VeoVideoProvider

    public function __construct(
        protected string $apiKey,
        protected string $baseUrl = 'https://apihub.agnes-ai.com/v1',
        protected string $model = 'agnes-video-v2.0',
    ) {}

    public function name(): string
    {
        return 'agnes';
    }

    public function generate(string $motionPrompt, string $sourceImageUrl): string
    {
        $taskId = $this->submit($motionPrompt, $sourceImageUrl);
        $videoUrl = $this->poll($taskId);

        return Http::timeout(60)->get($videoUrl)->throw()->body();
    }

    protected function submit(string $motionPrompt, string $sourceImageUrl): string
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post("{$this->baseUrl}/videos", [
                'model' => $this->model,
                'prompt' => $motionPrompt,
                'extra_body' => [
                    'image' => [$sourceImageUrl],
                    'mode' => 'ti2vid',
                ],
            ]);

        Log::info('Agnes video submit response', [
            'status' => $response->status(),
            'body' => $response->json(),
            'headers' => $response->headers(),
        ]);

        if ($response->failed()) {
            Log::error('AgnesAiVideoProvider: submit failed', [
                'body' => $response->body()
            ]);

            throw new RuntimeException(
                'Agnes AI video submit failed: ' . $response->body()
            );
        }

        $id = $response->json('video_id')
            ?? $response->json('id')
            ?? $response->json('task_id');

        if (!$id) {
            throw new RuntimeException(
                'Agnes AI did not return a video/task id: ' . $response->body()
            );
        }

        Log::info('Agnes video task ID selected', [
            'id' => $id,
        ]);

        return $id;
    }

    protected function poll(string $id): string
    {
        for ($i = 0; $i < self::MAX_POLL_ATTEMPTS; $i++) {
            sleep(self::POLL_INTERVAL_SECONDS);

            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->get("{$this->baseUrl}/videos/{$id}");

            if ($response->failed()) {
                throw new RuntimeException('Agnes AI video poll failed: ' . $response->body());
            }

            $status = $response->json('status');

            if ($status === 'failed' || $status === 'error') {
                throw new RuntimeException('Agnes AI video task failed: ' . $response->body());
            }

            if ($status === 'completed' || $status === 'succeeded') {
                $url = $response->json('video_url') ?? $response->json('metadata.url') ?? $response->json('url');

                if (!$url) {
                    throw new RuntimeException('Agnes AI task completed with no video URL: ' . $response->body());
                }

                return $url;
            }
        }

        throw new RuntimeException('Agnes AI video task timed out waiting for completion.');
    }
}
