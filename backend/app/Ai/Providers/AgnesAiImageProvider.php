<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\ImageProviderContract;
use App\Ai\Support\GeneratedImageFile;
use App\Support\BinaryDownloader;
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
 * MODEL CHOICE MATTERS HERE: Agnes documents `agnes-image-2.1-flash` as
 * their pure text-to-image model, and `agnes-image-2.0-flash` as the one
 * that actually does image-to-image editing / multi-image composition.
 * Using 2.1-flash for a reference-image request was the root cause of
 * scenes looking like "just a lightly edited character photo" instead of
 * a real composed scene — the model wasn't built for compositing multiple
 * reference images in the first place, regardless of what we sent it.
 * `run()` below picks the composition model automatically whenever
 * reference images are present, and only uses the text-to-image model for
 * a bare-prompt portrait with nothing to reference yet.
 *
 * `extra_body.image` (an array) is the correct, doc-confirmed field for
 * reference images — verified against multiple independent Agnes doc
 * pages, including an FAQ addressing this exact question. This differs
 * from the video endpoint, where `image` is a top-level field — the two
 * endpoints are inconsistent with each other, not a mistake here.
 */
class AgnesAiImageProvider implements ImageProviderContract
{
    public function __construct(
        protected ?string $apiKey,
        protected string $baseUrl = 'https://apihub.agnes-ai.com/v1',
        protected string $model = 'agnes-image-2.1-flash',
        protected string $compositionModel = 'agnes-image-2.0-flash',
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
        if (empty($this->apiKey)) {
            throw new RuntimeException('AGNES_API_KEY is not configured.');
        }

        try {
            $payload = [
                // Composition model whenever there's anything to compose
                // from — see class doc-comment for why this isn't just a
                // cosmetic choice.
                'model' => empty($referenceImageUrls) ? $this->model : $this->compositionModel,
                'prompt' => $prompt,
            ];

            if (!empty($referenceImageUrls)) {
                $payload['extra_body'] = [
                    'image' => array_values($referenceImageUrls),
                    // Explicit rather than relying on Agnes' default —
                    // this class expects url or b64_json either way, but
                    // pinning this avoids a silent format change breaking
                    // that assumption later.
                    'response_format' => 'url',
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
                // See App\Support\BinaryDownloader — handles Agnes CDN
                // responses that ignore Accept-Encoding negotiation and
                // force Brotli regardless (cURL error 61 otherwise).
                return new GeneratedImageFile(BinaryDownloader::get($data['url']));
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
