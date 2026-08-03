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
        // Together's FLUX.1 Kontext models accept a single `image_url` for
        // image-conditioned generation — genuinely useful for keeping a
        // character/environment consistent, but it's ONE image, not a
        // gallery: only the first reference gets used, and only when a
        // Kontext model is actually configured. Every other model on
        // Together ignores images entirely, which is exactly why
        // ImageGeneratorService checks supportsReferenceImages() up front
        // and falls back to folding descriptions into $prompt as text
        // instead of ever reaching this branch with a non-Kontext model.
        if ($this->supportsReferenceImages() && !empty($referenceImageUrls)) {
            return $this->run($prompt, $referenceImageUrls[0]);
        }

        return $this->run($prompt);
    }

    public function supportsReferenceImages(): bool
    {
        return str_contains(strtolower($this->model), 'kontext');
    }

    protected function run(string $prompt, ?string $referenceImageUrl = null)
    {
        try {
            $payload = [
                'model' => $this->model,
                'prompt' => $prompt,
                'response_format' => 'base64',
            ];

            if ($referenceImageUrl) {
                $payload['image_url'] = $referenceImageUrl;
            }

            $response = Http::withToken($this->apiKey)
                ->post('https://api.together.xyz/v1/images/generations', $payload)
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