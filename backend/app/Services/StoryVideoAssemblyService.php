<?php

namespace App\Services;

use App\Models\Story;
use App\Models\StoryImagePrompt;
use App\Models\StoryVideo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a story's scenes into one finished video with narration audio.
 *
 * Per scene, in story order (StoryImagePrompt::scopeOrdered):
 *  - if the scene already has a per-scene Video (Veo/Agnes motion clip),
 *    loop/trim it to exactly match that scene's narration length;
 *  - otherwise, do a simple Ken Burns pan/zoom over the still image for the
 *    same duration.
 * Every segment is normalized to the same resolution/fps/pixel format so
 * the concat step is a straight, lossless-ish join. Narration clips are
 * concatenated in the same order — because each visual segment's duration
 * exactly matches its narration clip's duration, concatenating both tracks
 * independently keeps them in sync without needing a timeline/overlay step.
 *
 * Requires ffmpeg + ffprobe on the server (see README deployment notes).
 * All ffmpeg/ffprobe calls go through Illuminate\Support\Facades\Process.
 */
class StoryVideoAssemblyService
{
    protected const WIDTH = 1080;
    protected const HEIGHT = 1920; // vertical, matches short-form/Pinterest use
    protected const FPS = 30;
    protected const DEFAULT_SCENE_SECONDS = 4.0; // fallback when a scene has no narration audio yet

    public function assemble(Story $story): StoryVideo
    {
        $storyVideo = StoryVideo::firstOrCreate(
            ['story_id' => $story->id],
            ['user_id' => $story->user_id]
        );

        $scenes = $story->imagePrompts()
            ->ordered()
            ->whereNotNull('image_generated_url')
            ->get();

        if ($scenes->isEmpty()) {
            throw new RuntimeException('Story has no generated scenes yet.');
        }

        $storyVideo->update(['status' => StoryVideo::STATUS_PROCESSING, 'last_generation_error' => null]);

        $workDir = storage_path('app/tmp/story-video-' . $story->id . '-' . Str::random(8));
        mkdir($workDir, 0755, true);

        try {
            $segmentPaths = [];
            $audioPaths = [];

            foreach ($scenes as $index => $scene) {
                $duration = $scene->narration_audio_seconds ?? self::DEFAULT_SCENE_SECONDS;

                $segmentPaths[] = $this->buildVisualSegment($scene, $duration, $workDir, $index);

                if ($scene->narration_audio_url) {
                    $audioPaths[] = $this->download($scene->narration_audio_url, "{$workDir}/audio-{$index}.mp3");
                }
            }

            $visualPath = $this->concat($segmentPaths, $workDir, 'visual.mp4', video: true);
            $finalPath = "{$workDir}/final.mp4";

            if (!empty($audioPaths)) {
                $audioPath = $this->concat($audioPaths, $workDir, 'narration.mp3', video: false);
                $this->mux($visualPath, $audioPath, $finalPath);
            } else {
                // No narration generated yet for any scene — ship a silent
                // cut rather than failing outright, so the video isn't
                // blocked purely on TTS having run.
                copy($visualPath, $finalPath);
            }

            $durationSeconds = $this->probeDuration($finalPath);

            $storagePath = "story-videos-full/{$story->id}/" . Str::random(8) . '.mp4';
            Storage::disk('public')->put($storagePath, file_get_contents($finalPath));

            $storyVideo->update([
                'video_url' => Storage::disk('public')->url($storagePath),
                'duration_seconds' => $durationSeconds,
                'scene_count' => $scenes->count(),
                'status' => StoryVideo::STATUS_READY,
                'generated_at' => now(),
                'last_generation_error' => null,
            ]);

            Log::info('StoryVideoAssemblyService: assembled', ['story_id' => $story->id, 'scene_count' => $scenes->count()]);

            return $storyVideo->fresh();
        } catch (\Throwable $e) {
            $storyVideo->update([
                'status' => StoryVideo::STATUS_FAILED,
                'last_generation_error' => Str::limit($e->getMessage(), 2000),
                'generation_attempts' => $storyVideo->generation_attempts + 1,
            ]);

            Log::error('StoryVideoAssemblyService: assembly failed', [
                'story_id' => $story->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->cleanup($workDir);
        }
    }

    /**
     * One scene -> one silent video segment of exactly $duration seconds,
     * normalized to a common resolution/fps so every segment concatenates
     * cleanly regardless of source size/aspect ratio.
     */
    protected function buildVisualSegment(StoryImagePrompt $scene, float $duration, string $workDir, int $index): string
    {
        $outPath = "{$workDir}/segment-{$index}.mp4";
        $scale = self::WIDTH . ':' . self::HEIGHT;
        $vf = "scale={$scale}:force_original_aspect_ratio=decrease,pad={$scale}:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=" . self::FPS;

        $video = $scene->video; // StoryImagePrompt::video() -> hasOneThrough VideoPrompt -> Video

        if ($video && $video->video_url) {
            $sourcePath = $this->download($video->video_url, "{$workDir}/source-video-{$index}.mp4");

            // -stream_loop -1 loops the source indefinitely; -t caps the
            // output at $duration either way, so this handles a source
            // clip that's shorter OR longer than the target duration with
            // one command.
            $result = Process::timeout(120)->run([
                'ffmpeg', '-y', '-stream_loop', '-1', '-i', $sourcePath,
                '-t', (string) $duration,
                '-vf', $vf,
                '-an', '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'veryfast',
                $outPath,
            ]);
        } else {
            $imageUrl = $scene->image_generated_url_quality ?: $scene->image_generated_url;
            $sourcePath = $this->download($imageUrl, "{$workDir}/source-image-{$index}.jpg");
            $frames = max(1, (int) round($duration * self::FPS));

            // Gentle Ken Burns zoom — subtle enough not to look chaotic on a
            // still image held for several seconds.
            $zoompan = "zoompan=z='min(zoom+0.0006,1.15)':d={$frames}:s={$scale}:fps=" . self::FPS;

            $result = Process::timeout(120)->run([
                'ffmpeg', '-y', '-loop', '1', '-i', $sourcePath,
                '-vf', "{$zoompan},setsar=1",
                '-t', (string) $duration,
                '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'veryfast',
                $outPath,
            ]);
        }

        if ($result->failed()) {
            throw new RuntimeException("ffmpeg segment build failed for scene {$scene->id}: " . $result->errorOutput());
        }

        return $outPath;
    }

    /** Concat demuxer join — video segments or audio clips, same approach either way. */
    protected function concat(array $paths, string $workDir, string $outFilename, bool $video): string
    {
        $listPath = "{$workDir}/" . ($video ? 'videos' : 'audios') . '.txt';
        $list = collect($paths)->map(fn ($p) => "file '" . addslashes($p) . "'")->implode("\n");
        file_put_contents($listPath, $list);

        $outPath = "{$workDir}/{$outFilename}";

        $args = ['ffmpeg', '-y', '-f', 'concat', '-safe', '0', '-i', $listPath];

        $args = $video
            ? [...$args, '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'veryfast', $outPath]
            : [...$args, '-c:a', 'aac', '-b:a', '192k', $outPath];

        $result = Process::timeout(180)->run($args);

        if ($result->failed()) {
            throw new RuntimeException("ffmpeg concat failed: " . $result->errorOutput());
        }

        return $outPath;
    }

    protected function mux(string $visualPath, string $audioPath, string $outPath): void
    {
        $result = Process::timeout(120)->run([
            'ffmpeg', '-y', '-i', $visualPath, '-i', $audioPath,
            '-c:v', 'copy', '-c:a', 'aac', '-shortest', '-movflags', '+faststart',
            $outPath,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('ffmpeg mux failed: ' . $result->errorOutput());
        }
    }

    protected function probeDuration(string $path): ?float
    {
        $result = Process::run([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ]);

        if ($result->failed()) {
            return null;
        }

        $seconds = (float) trim($result->output());

        return $seconds > 0 ? round($seconds, 2) : null;
    }

    protected function download(string $url, string $destination): string
    {
        // Accept-Encoding: identity — same cURL-61/Brotli issue as the
        // Agnes providers; this method downloads whatever URL a scene
        // points at (image, per-scene video, narration audio), so it's
        // the single highest-exposure spot for that bug in this app.
        $bytes = Http::withHeaders(['Accept-Encoding' => 'identity'])
            ->timeout(60)->get($url)->throw()->body();
        file_put_contents($destination, $bytes);

        return $destination;
    }

    protected function cleanup(string $workDir): void
    {
        if (!is_dir($workDir)) {
            return;
        }

        foreach (glob("{$workDir}/*") ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($workDir);
    }
}
