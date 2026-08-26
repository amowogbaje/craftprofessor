<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\VideoProviderContract;
use App\Services\GoogleServiceAccountAuth;
use App\Support\BinaryDownloader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Calls Veo on Vertex AI (image-to-video). Veo runs as a long-running
 * operation: submit, then poll fetchPredictOperation until it reports done.
 *
 * This is the same submit()/poll() logic that used to live directly inside
 * VideoGeneratorService — extracted behind VideoProviderContract so a
 * second provider (Agnes AI) can be swapped in via config('ai.default_video_provider')
 * without touching VideoGeneratorService itself.
 */
class VeoVideoProvider implements VideoProviderContract
{
    protected const POLL_INTERVAL_SECONDS = 8;
    protected const MAX_POLL_ATTEMPTS = 45; // ~6 minutes

    public function __construct(protected GoogleServiceAccountAuth $auth)
    {
    }

    public function name(): string
    {
        return 'veo';
    }

    public function generate(string $motionPrompt, string $sourceImageUrl): string
    {
        $operationName = $this->submit($motionPrompt, $sourceImageUrl);

        return $this->poll($operationName);
    }

    protected function submit(string $motionPrompt, string $sourceImageUrl): string
    {
        $config = config('ai.providers.veo');
        $token = $this->auth->getAccessToken();

        // See App\Support\BinaryDownloader — guards against cURL error 61
        // if the image host (e.g. Agnes, if that's the active image
        // provider) sends a Content-Encoding curl can't auto-decode.
        $imageBytes = base64_encode(BinaryDownloader::get($sourceImageUrl, 30));

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
            Log::error('VeoVideoProvider: submit failed', ['body' => $response->body()]);
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
