<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\TtsProviderContract;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\RateLimitedException;

/**
 * OpenAI's /v1/audio/speech endpoint. Called directly via HTTP rather than
 * through laravel/ai's Audio facade (if one exists in this package version)
 * so the request/response shape here is explicit and doesn't depend on
 * package internals we haven't verified.
 */
class OpenAiTtsProvider implements TtsProviderContract
{
    public function __construct(
        protected string $apiKey,
        protected string $baseUrl = 'https://api.openai.com/v1',
        protected string $model = 'gpt-4o-mini-tts',
        protected string $voice = 'alloy',
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function speak(string $text): string
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post("{$this->baseUrl}/audio/speech", [
                    'model' => $this->model,
                    'voice' => $this->voice,
                    'input' => $text,
                    'response_format' => 'mp3',
                ])
                ->throw();

            return $response->body();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw new RateLimitedException($e->getMessage(), previous: $e);
            }
            throw $e;
        }
    }
}
