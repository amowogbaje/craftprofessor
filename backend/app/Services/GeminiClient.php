<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Thin wrapper around the Gemini generateContent REST endpoint (free tier).
 *
 * NOTE: Google renames/deprecates Gemini model ids fairly often. The exact
 * model strings live in config/services.php ('gemini.text_model' and
 * 'gemini.image_model') so you can bump them without touching code.
 * Verify current model availability at ai.google.dev before relying on this
 * in production — I can't guarantee the configured names are still live.
 */
class GeminiClient
{
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');

        if (empty($this->apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }
    }

    /**
     * Plain text generation (used by ImagePromptAgent).
     */
    public function generateText(string $prompt, ?string $systemInstruction = null): string
    {
        $model = config('services.gemini.text_model', 'gemini-2.0-flash');

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
        ];

        if ($systemInstruction) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        $response = Http::timeout(60)
            ->post("{$this->baseUrl}/{$model}:generateContent?key={$this->apiKey}", $payload);

        if ($response->failed()) {
            Log::error('GeminiClient: text generation HTTP failure', [
                'model' => $model,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 1500),
            ]);
            throw new RuntimeException("Gemini text generation failed: {$response->body()}");
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (!$text) {
            Log::error('GeminiClient: text generation returned no content', [
                'model' => $model,
                'response' => Str::limit(json_encode($response->json()), 1500),
            ]);
            throw new RuntimeException('Gemini returned no text content.');
        }

        return $text;
    }

    /**
     * Image generation, optionally grounded with reference images
     * (e.g. character reference art for visual consistency).
     *
     * @param  array<int, string>  $referenceImageUrls
     * @return string base64-encoded PNG/JPEG bytes
     */
    public function generateImage(string $prompt, array $referenceImageUrls = []): string
    {
        $model = config('services.gemini.image_model', 'gemini-2.5-flash-image');

        $parts = [['text' => $prompt]];

        foreach ($referenceImageUrls as $url) {
            $inline = $this->fetchAsInlineImage($url);
            if ($inline) {
                $parts[] = ['inlineData' => $inline];
            }
        }

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => $parts],
            ],
        ];

        $response = Http::timeout(120)
            ->post("{$this->baseUrl}/{$model}:generateContent?key={$this->apiKey}", $payload);

        if ($response->failed()) {
            Log::error('GeminiClient: image generation HTTP failure', [
                'model' => $model,
                'status' => $response->status(),
                'reference_image_count' => count($referenceImageUrls),
                'body' => Str::limit($response->body(), 1500),
            ]);
            throw new RuntimeException("Gemini image generation failed: {$response->body()}");
        }

        $imageParts = data_get($response->json(), 'candidates.0.content.parts', []);

        foreach ($imageParts as $part) {
            if (isset($part['inlineData']['data'])) {
                return $part['inlineData']['data']; // base64 string
            }
        }

        Log::error('GeminiClient: image generation returned no image data', [
            'model' => $model,
            'response' => Str::limit(json_encode($response->json()), 1500),
        ]);
        throw new RuntimeException('Gemini response contained no image data.');
    }

    protected function fetchAsInlineImage(string $url): ?array
    {
        try {
            $response = Http::timeout(30)->get($url);

            if ($response->failed()) {
                return null;
            }

            $mime = $response->header('Content-Type') ?: 'image/jpeg';

            return [
                'mimeType' => $mime,
                'data' => base64_encode($response->body()),
            ];
        } catch (\Throwable $e) {
            Log::error('GeminiClient: failed to fetch reference image', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            report($e);
            return null;
        }
    }
}
