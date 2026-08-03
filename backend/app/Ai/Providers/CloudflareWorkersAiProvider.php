<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\ImageProviderContract;
use App\Ai\Support\GeneratedImageFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\RateLimitedException;

class CloudflareWorkersAiProvider implements ImageProviderContract
{
    public function __construct(
        protected string $accountId,
        protected string $apiToken,
        protected string $model = '@cf/stabilityai/stable-diffusion-xl-base-1.0',
    ) {}

    public function generatePortrait(?string $prompt)
    {
        return $this->run($prompt ?? '');
    }

    public function generateScene(string $prompt, array $referenceImageUrls = [])
    {
        // Workers AI SDXL doesn't take reference images — ImageGeneratorService
        // already knows this via supportsReferenceImages() and won't even try
        // to pass $referenceImageUrls here; it folds character/environment/prop
        // descriptions into $prompt as text instead before calling this.
        return $this->run($prompt);
    }

    public function supportsReferenceImages(): bool
    {
        return false;
    }

    protected function run(string $prompt)
    {
        try {
            $response = Http::withToken($this->apiToken)
                ->post("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/ai/run/{$this->model}", [
                    'prompt' => $prompt,
                ])
                ->throw();

            return new GeneratedImageFile($response->body());
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw new RateLimitedException($e->getMessage(), previous: $e);
            }
            throw $e;
        }
    }
}