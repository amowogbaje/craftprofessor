<?php

namespace App\Services;

use App\Ai\Contracts\TtsProviderContract;
use App\Exceptions\UserGenerationLimitReached;
use App\Models\Character;
use App\Models\StoryImagePrompt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns a scene's spoken audio into a stored file plus its measured
 * duration — the duration is what StoryVideoAssemblyService uses to decide
 * how long each scene holds on screen, so picture and speech stay in sync.
 *
 * Two shapes, same output columns either way (narration_audio_url /
 * narration_audio_seconds hold the scene's *total* audio regardless of
 * which path produced it, so nothing downstream needs to know which one
 * ran):
 *  - Plain narration (the common case): one `speak()` call on
 *    $imagePrompt->narration.
 *  - Dialogue (see ImagePromptAgent rule 6 / StoryImagePrompt::dialogue_lines):
 *    one `speak()` call PER LINE, voiced with that line's character's
 *    assigned Character::voice when set, then concatenated in order with
 *    ffmpeg — same concat-demuxer approach StoryVideoAssemblyService
 *    already uses for stitching scenes together. Each line's own
 *    audio_url/audio_seconds get written back onto dialogue_lines too, so
 *    StoryVideoAssemblyService can caption each line as its own
 *    "NAME: ..." card timed to its real (measured) duration instead of
 *    guessing from word count the way single-narration captions do.
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
        if ($imagePrompt->hasDialogue()) {
            return $this->generateDialogue($imagePrompt);
        }

        return $this->generateNarration($imagePrompt);
    }

    protected function generateNarration(StoryImagePrompt $imagePrompt): bool
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
     * Same coin/error/notification shape as generateNarration() above, just
     * one TTS call per dialogue line instead of one call total, each voiced
     * per-character and then concatenated into the scene's single audio
     * track. Charged the same narration_audio cost regardless of line
     * count — dialogue is still "this scene's spoken audio," not N
     * separate narration generations — since per-line coin metering would
     * make a 4-line back-and-forth cost 4x a plain narration scene for no
     * reason a user would find intuitive.
     */
    protected function generateDialogue(StoryImagePrompt $imagePrompt): bool
    {
        $lines = $imagePrompt->dialogue_lines;

        if (empty($lines)) {
            return false;
        }

        $user = $imagePrompt->user ?? $imagePrompt->story?->user;
        $cost = (int) config('coins.costs.narration_audio');

        if ($user) {
            if (!$this->wallet->canAfford($user, $cost)) {
                Log::info('NarrationAudioService: insufficient coins, skipping dialogue', ['id' => $imagePrompt->id, 'user_id' => $user->id]);
                throw new UserGenerationLimitReached($user->id, 'insufficient coins');
            }
            $this->wallet->debit($user, $cost, 'narration_audio', $imagePrompt);
        }

        $voicesByName = $this->voicesForScene($imagePrompt);
        $workDir = storage_path('app/tmp/dialogue-' . $imagePrompt->id . '-' . Str::random(8));
        mkdir($workDir, 0755, true);

        try {
            $ext = $this->tts->extension();
            $clipPaths = [];
            $updatedLines = [];
            $totalSeconds = 0.0;

            foreach ($lines as $i => $line) {
                $voice = $voicesByName[Str::lower($line['character_name'] ?? '')] ?? null;
                $bytes = $this->tts->speak($line['text'], $voice);

                $clipPath = "{$workDir}/line-{$i}.{$ext}";
                file_put_contents($clipPath, $bytes);
                $clipPaths[] = $clipPath;

                $lineSeconds = $this->probeDuration($clipPath) ?? 0.0;
                $totalSeconds += $lineSeconds;

                $updatedLines[] = [
                    'character_name' => $line['character_name'],
                    'text' => $line['text'],
                    'audio_url' => null, // filled in below once the per-line file is in permanent storage
                    'audio_seconds' => $lineSeconds,
                ];
            }

            // Store each line's own clip permanently too (not just the
            // concatenated whole) — StoryVideoAssemblyService only needs
            // durations for timing captions, but keeping the individual
            // files around costs little and makes debugging a specific
            // line's TTS output straightforward.
            foreach ($clipPaths as $i => $clipPath) {
                $linePath = "narration-audio/{$imagePrompt->story_id}/{$imagePrompt->id}-line{$i}-" . Str::random(6) . ".{$ext}";
                Storage::disk('public')->put($linePath, file_get_contents($clipPath));
                $updatedLines[$i]['audio_url'] = Storage::disk('public')->url($linePath);
            }

            // Multi-line concat re-encodes to AAC (see
            // StoryVideoAssemblyService::assemble()'s doc-comment on why an
            // AAC bitstream needs an .aac container, not .mp3/.wav) — a
            // single-line "dialogue" (one character talking, still routed
            // through this path so it gets per-line captioning) has
            // nothing to concatenate, so it stays in the TTS provider's
            // native format untouched.
            if (count($clipPaths) === 1) {
                $mergedPath = $clipPaths[0];
                $finalExt = $ext;
            } else {
                $mergedPath = $this->concatAudio($clipPaths, $workDir, 'merged.aac');
                $finalExt = 'aac';
            }

            $finalPath = "narration-audio/{$imagePrompt->story_id}/{$imagePrompt->id}-" . Str::random(8) . ".{$finalExt}";
            Storage::disk('public')->put($finalPath, file_get_contents($mergedPath));

            $imagePrompt->update([
                'narration_audio_url' => Storage::disk('public')->url($finalPath),
                'narration_audio_seconds' => round($totalSeconds, 2) ?: $this->probeDuration($mergedPath),
                'dialogue_lines' => $updatedLines,
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

            Log::error('NarrationAudioService: dialogue generation failed', [
                'story_image_prompt_id' => $imagePrompt->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            foreach (glob("{$workDir}/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($workDir);
        }
    }

    /** Maps lower-cased character name -> assigned voice, for this scene's cast only. */
    protected function voicesForScene(StoryImagePrompt $imagePrompt): array
    {
        return $imagePrompt->mainCharacters()
            ->filter(fn (Character $c) => !empty($c->voice))
            ->mapWithKeys(fn (Character $c) => [Str::lower($c->name) => $c->voice])
            ->all();
    }

    /** Concat demuxer join for the per-line audio clips — mirrors StoryVideoAssemblyService::concat(). */
    protected function concatAudio(array $paths, string $workDir, string $outFilename): string
    {
        $listPath = "{$workDir}/" . Str::random(6) . '-lines.txt';
        $list = collect($paths)->map(fn ($p) => "file '" . addslashes($p) . "'")->implode("\n");
        file_put_contents($listPath, $list);

        $outPath = "{$workDir}/{$outFilename}";

        $result = Process::timeout(60)->run([
            'ffmpeg', '-y', '-f', 'concat', '-safe', '0', '-i', $listPath,
            '-c:a', 'aac', '-b:a', '192k', $outPath,
        ]);

        if ($result->failed()) {
            throw new \RuntimeException('ffmpeg dialogue concat failed: ' . $result->errorOutput());
        }

        return $outPath;
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
