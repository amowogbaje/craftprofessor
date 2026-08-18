<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\TtsProviderContract;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\RateLimitedException;

/**
 * ElevenLabs text-to-speech — an alternative to OpenAI's TTS, since
 * config/ai.php already had an 'eleven' provider entry defined (unused
 * until now). Swap via TTS_PROVIDER=eleven in .env.
 */
class ElevenLabsTtsProvider implements TtsProviderContract
{
    public function __construct(
        protected string $apiKey,
        protected string $voiceId,
        protected string $baseUrl = 'https://api.elevenlabs.io/v1',
        protected string $model = 'eleven_multilingual_v2',
    ) {}

    public function name(): string
    {
        return 'eleven';
    }

    public function speak(string $text): string
    {
        try {
            $response = Http::withHeaders(['xi-api-key' => $this->apiKey])
                ->timeout(60)
                ->post("{$this->baseUrl}/text-to-speech/{$this->voiceId}", [
                    'text' => $text,
                    'model_id' => $this->model,
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
