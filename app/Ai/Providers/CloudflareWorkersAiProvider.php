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
        // Workers AI SDXL doesn't take reference images; fold character
        // descriptions into the prompt text if you have them, or ignore.
        return $this->run($prompt);
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