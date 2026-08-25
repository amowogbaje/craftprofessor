<?php

namespace App\Services;

use App\Ai\Contracts\TtsProviderContract;
use App\Exceptions\UserGenerationLimitReached;
use App\Models\StoryImagePrompt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns a scene's `narration` line (see ImagePromptAgent) into a stored mp3
 * plus its measured duration — the duration is what StoryVideoAssemblyService
 * uses to decide how long each scene holds on screen, so picture and speech
 * stay in sync.
 */
class NarrationAudioService
{
    public function __construct(
        protected TtsProviderContract $tts,
        protected WalletService $wallet,
    ) {
    }

    public function generate(StoryImagePrompt $imagePrompt): bool
    {
        if (empty($imagePrompt->narration)) {
            return false;
        }

        $user = $imagePrompt->user ?? $imagePrompt->story?->user;
        $cost = (int) config('coins.costs.narration_audio');

        if ($user) {
            if (!$this->wallet->canAfford($user, $cost)) {
                Log::info('NarrationAudioService: insufficient coins, skipping', ['id' => $imagePrompt->id, 'user_id' => $user->id]);
                throw new UserGenerationLimitReached($user->id, 'insufficient coins');
            }
            $this->wallet->debit($user, $cost, 'narration_audio', $imagePrompt);
        }

        try {
            $bytes = $this->tts->speak($imagePrompt->narration);

            $ext = $this->tts->extension();
            $path = "narration-audio/{$imagePrompt->story_id}/{$imagePrompt->id}-" . Str::random(8) . ".{$ext}";
            Storage::disk('public')->put($path, $bytes);

            $seconds = $this->probeDuration(Storage::disk('public')->path($path));

            $imagePrompt->update([
                'narration_audio_url' => Storage::disk('public')->url($path),
                'narration_audio_seconds' => $seconds,
                'last_narration_error' => null,
            ]);

            return true;
        } catch (\Throwable $e) {
            if ($user) {
                $this->wallet->refund($user, $cost, 'narration_audio', $imagePrompt, ['error' => $e->getMessage()]);
            }

            $imagePrompt->update([
                'last_narration_error' => Str::limit($e->getMessage(), 2000),
                'generation_attempts' => $imagePrompt->generation_attempts + 1,
            ]);

            Log::error('NarrationAudioService: generation failed', [
                'story_image_prompt_id' => $imagePrompt->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Reads the audio's real duration via ffprobe rather than estimating
     * from word/character count — TTS pacing varies enough by provider and
     * voice that an estimate would drift out of sync over a multi-scene video.
     * Requires ffmpeg/ffprobe on the server (see README deployment notes).
     */
    protected function probeDuration(string $absolutePath): ?float
    {
        $result = Process::run([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $absolutePath,
        ]);

        if ($result->failed()) {
            Log::warning('NarrationAudioService: ffprobe failed, duration unknown', ['error' => $result->errorOutput()]);
            return null;
        }

        $seconds = (float) trim($result->output());

        return $seconds > 0 ? round($seconds, 2) : null;
    }
}
