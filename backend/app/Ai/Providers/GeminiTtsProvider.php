<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\TtsProviderContract;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;

/**
 * Gemini's native text-to-speech (generateContent with
 * responseModalities: ["AUDIO"]) — free-tier, and reuses the same
 * GEMINI_API_KEY already used for text/image generation elsewhere in this
 * app, so switching TTS_PROVIDER=gemini needs zero new secrets.
 *
 * Built from Google's own docs (ai.google.dev/gemini-api/docs/generate-content/speech-generation),
 * which is a stable, well-documented API — more confidence here than the
 * Agnes AI providers, which were built from scattered third-party sources.
 *
 * Gemini returns raw 16-bit PCM (mimeType like "audio/L16;rate=24000"),
 * not a playable file on its own — wrapWav() prepends a standard 44-byte
 * WAV header so the bytes this returns are a normal, playable .wav file.
 */
class GeminiTtsProvider implements TtsProviderContract
{
    public function __construct(
        protected ?string $apiKey,
        protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        protected string $model = 'gemini-2.5-flash-preview-tts',
        protected string $voice = 'Kore',
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function extension(): string
    {
        return 'wav';
    }

    public function speak(string $text, ?string $voice = null): string
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout(60)
                ->post("{$this->baseUrl}/models/{$this->model}:generateContent", [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [['text' => $text]],
                    ]],
                    'generationConfig' => [
                        'responseModalities' => ['AUDIO'],
                        'speechConfig' => [
                            'voiceConfig' => [
                                'prebuiltVoiceConfig' => ['voiceName' => $voice ?: $this->voice],
                            ],
                        ],
                    ],
                ])
                ->throw();

            $part = $response->json('candidates.0.content.parts.0.inlineData');

            if (empty($part['data'])) {
                throw new RuntimeException('Gemini TTS returned no audio data: ' . $response->body());
            }

            $pcm = base64_decode($part['data']);
            $sampleRate = $this->parseSampleRate($part['mimeType'] ?? '');

            return $this->wrapWav($pcm, $sampleRate);
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw new RateLimitedException($e->getMessage(), previous: $e);
            }
            throw $e;
        }
    }

    /** mimeType looks like "audio/L16;codec=pcm;rate=24000" — pull the rate out, default 24000 if absent. */
    protected function parseSampleRate(string $mimeType): int
    {
        if (preg_match('/rate=(\d+)/', $mimeType, $m)) {
            return (int) $m[1];
        }

        return 24000;
    }

    /** Prepends a standard 44-byte WAV header to raw 16-bit mono PCM data. */
    protected function wrapWav(string $pcm, int $sampleRate, int $channels = 1, int $bitsPerSample = 16): string
    {
        $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
        $blockAlign = $channels * ($bitsPerSample / 8);
        $dataSize = strlen($pcm);

        $header = 'RIFF'
            . pack('V', 36 + $dataSize)
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)          // fmt chunk size
            . pack('v', 1)           // PCM format
            . pack('v', $channels)
            . pack('V', $sampleRate)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', $bitsPerSample)
            . 'data'
            . pack('V', $dataSize);

        return $header . $pcm;
    }
}
