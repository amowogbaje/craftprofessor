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
        protected ?string $apiKey,
        protected string $baseUrl = 'https://api.openai.com/v1',
        protected string $model = 'gpt-4o-mini-tts',
        protected string $voice = 'alloy',
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function extension(): string
    {
        return 'mp3';
    }

    public function speak(string $text, ?string $voice = null): string
    {
        if (empty($this->apiKey)) {
            // A missing key must fail here, not at construction — this
            // class gets built eagerly whenever TtsProviderContract is
            // resolved (e.g. every run of story:generate-narration-audio),
            // so a hard constructor requirement would crash that command
            // on every single invocation instead of just the scenes that
            // actually need narration.
            throw new \RuntimeException('OPENAI_API_KEY is not configured — set it in .env or switch TTS_PROVIDER.');
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post("{$this->baseUrl}/audio/speech", [
                    'model' => $this->model,
                    'voice' => $voice ?: $this->voice,
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
