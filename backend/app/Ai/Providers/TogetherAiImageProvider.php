<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\ImageProviderContract;
use App\Ai\Support\GeneratedImageFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\RateLimitedException;

class TogetherAiImageProvider implements ImageProviderContract
{
    public function __construct(
        protected string $apiKey,
        protected string $model = 'black-forest-labs/FLUX.1-schnell',
    ) {}

    public function generatePortrait(?string $prompt)
    {
        return $this->run($prompt ?? '');
    }

    public function generateScene(string $prompt, array $referenceImageUrls = [])
    {
        // Together's FLUX Kontext models accept image_url params for
        // reference/image-to-image if you want character consistency;
        // check current model docs for the exact param name before wiring this up.
        return $this->run($prompt);
    }

    protected function run(string $prompt)
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->post('https://api.together.xyz/v1/images/generations', [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'response_format' => 'base64',
                ])
                ->throw();

            $b64 = $response->json('data.0.b64_json');
            return new GeneratedImageFile(base64_decode($b64));
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw new RateLimitedException($e->getMessage(), previous: $e);
            }
            throw $e;
        }
    }
}