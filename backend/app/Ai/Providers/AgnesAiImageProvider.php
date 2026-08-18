<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\ImageProviderContract;
use App\Ai\Support\GeneratedImageFile;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;

/**
 * Agnes AI image provider — OpenAI-Images-compatible endpoint that also
 * accepts reference image URLs (img2img / multi-image composition), which
 * is the reason it exists alongside Cloudflare/Together: it lets us keep a
 * character/environment/prop visually consistent across every scene it
 * appears in, for free, instead of folding a text description into the
 * prompt and hoping.
 *
 * NOTE ON VERIFICATION: this is built from Agnes AI's own published docs
 * (https://agnes-ai.com/doc/agnes-image-12 and the "Image 2.0" reference),
 * but I was not able to exercise it against a live account. Endpoint path,
 * field names (`extra_body.image` vs a top-level `image`), and the response
 * shape should be double-checked against your actual Agnes dashboard docs
 * once you have a key — everything provider-specific is config-driven
 * below so a mismatch is a one-file fix, not a rewrite.
 */
class AgnesAiImageProvider implements ImageProviderContract
{
    public function __construct(
        protected string $apiKey,
        protected string $baseUrl = 'https://apihub.agnes-ai.com/v1',
        protected string $model = 'agnes-image-2.1-flash',
    ) {}

    public function generatePortrait(?string $prompt)
    {
        return $this->run($prompt ?? '');
    }

    public function generateScene(string $prompt, array $referenceImageUrls = [])
    {
        return $this->run($prompt, $referenceImageUrls);
    }

    public function supportsReferenceImages(): bool
    {
        return true;
    }

    protected function run(string $prompt, array $referenceImageUrls = [])
    {
        try {
            $payload = [
                'model' => $this->model,
                'prompt' => $prompt,
            ];

            // Agnes' img2img / composition path: pass known reference
            // images (character portraits, environment/prop references)
            // straight through as pixels instead of describing them in
            // text. Docs reference this as extra_body.image — an array,
            // so multiple references (character + environment + prop) can
            // all be handed over for one scene at once.
            if (!empty($referenceImageUrls)) {
                $payload['extra_body'] = [
                    'image' => array_values($referenceImageUrls),
                ];
            }

            $response = Http::withToken($this->apiKey)
                ->timeout(120)
                ->post("{$this->baseUrl}/images/generations", $payload)
                ->throw();

            $data = $response->json('data.0');

            if (!$data) {
                throw new RuntimeException('Agnes AI returned no image data: ' . $response->body());
            }

            // Prefer inline base64 if Agnes sent it; otherwise download the
            // returned URL (matches the shape shown in Agnes' own example
            // response: {"data":[{"url": "..."}]}).
            if (!empty($data['b64_json'])) {
                return new GeneratedImageFile(base64_decode($data['b64_json']));
            }

            if (!empty($data['url'])) {
                $bytes = Http::timeout(60)->get($data['url'])->throw()->body();
                return new GeneratedImageFile($bytes);
            }

            throw new RuntimeException('Agnes AI response had neither b64_json nor url: ' . $response->body());
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw new RateLimitedException($e->getMessage(), previous: $e);
            }
            Log::error('AgnesAiImageProvider: request failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
