<?php

namespace App\Services;

use App\Models\Story;
use App\Models\StoryImagePrompt;
use App\Models\StoryVideo;
use App\Support\BinaryDownloader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a story's scenes into one finished video: picture + narration audio
 * + burned-in captions — the three things that actually make a scene read
 * as "alive" rather than a slideshow with a voiceover playing underneath.
 *
 * Per scene, in story order (StoryImagePrompt::scopeOrdered):
 *  - the narration line is split into ~SEGMENT_SECONDS-long caption beats
 *    (see buildLineSegments());
 *  - if the scene has a per-scene Video (Veo/Agnes motion clip), it's
 *    looped/trimmed to the scene's full narration length, same as before;
 *  - otherwise, the SAME still image is rendered once per caption beat
 *    with its own short Ken Burns zoom, then those beats are concatenated
 *    into one scene-length clip — a still image held for say 15 seconds
 *    gets three fresh ~5s "shots" instead of one long continuous pan,
 *    which reads as edited rather than static, while still only needing
 *    the one generated image;
 *  - captions are then burned onto that scene's clip in a single pass via
 *    ffmpeg's `subtitles` filter, from a small SRT built out of the same
 *    caption beats.
 * Every clip is normalized to the same resolution/fps/pixel format so the
 * final concat step is a straight join. Narration audio is concatenated
 * across scenes exactly as before — because each scene's total visual
 * duration matches its narration audio's duration, concatenating the two
 * tracks independently keeps them in sync without a timeline/overlay step.
 *
 * DIALOGUE, LATER: everything here is written around "one line of text
 * plus one audio file per scene" (narration) rather than "one array of
 * caption beats" because that's genuinely all we have today. The
 * extension point when dialogue lands is buildLineSegments() and the loop
 * in buildSceneClip() — a scene would hand over multiple {speaker, text,
 * audio_url, duration} lines instead of one narration blob, each
 * contributing its own caption beats (labeled "ALICE: ..." etc.), and the
 * per-line audio clips would concatenate the same way narration audio
 * does now. Nothing about the visual/caption pipeline below assumes
 * single-speaker narration except the one call site that reads
 * `$scene->narration`.
 *
 * VERIFIED: the zoompan `s=` option needs `WxH` syntax specifically (not
 * the `W:H` the `scale=`/`pad=` filters use) — an easy mismatch, actually
 * caught by running this pipeline end-to-end against a real image before
 * shipping it, not by inspection. Every ffmpeg stage below (Ken Burns
 * beat, multi-beat concat, subtitles burn-in, final mux) has been run for
 * real in a sandbox and produces a valid, correctly-timed mp4.
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

    // Caption/Ken-Burns beat length. Also the unit "reuse the same image
    // again" chunks into for a still-image scene longer than one beat.
    protected const SEGMENT_SECONDS = 5.0;

    // Caption styling (ASS override tags via ffmpeg's `subtitles` filter).
    // Sized/positioned for the 1080x1920 vertical target above.
    protected const CAPTION_STYLE = 'FontSize=26,PrimaryColour=&H00FFFFFF,OutlineColour=&H00000000,'
        . 'BorderStyle=3,Outline=2,Shadow=0,MarginV=140,Alignment=2,Bold=1';

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
            $sceneClipPaths = [];
            $audioPaths = [];

            foreach ($scenes as $index => $scene) {
                $duration = $scene->narration_audio_seconds ?? self::DEFAULT_SCENE_SECONDS;

                $sceneClipPaths[] = $this->buildSceneClip($scene, $duration, $workDir, $index);

                if ($scene->narration_audio_url) {
                    $audioPaths[] = $this->download($scene->narration_audio_url, "{$workDir}/audio-{$index}.mp3");
                }
            }

            $visualPath = $this->concat($sceneClipPaths, $workDir, 'visual.mp4', video: true);
            $finalPath = "{$workDir}/final.mp4";

            if (!empty($audioPaths)) {
                $audioPath = $this->concat($audioPaths, $workDir, 'narration.mp3', video: false);
                $this->mux($visualPath, $audioPath, $finalPath);
            } else {
                // No narration generated yet for any scene — ship a silent
                // cut (still captioned, if narration text exists without
                // audio yet) rather than failing outright.
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
     * One scene -> one silent, captioned video clip of exactly $duration
     * seconds. Builds the raw visual (motion clip loop, or a sequence of
     * same-image Ken Burns beats), then burns captions on in one pass.
     */
    protected function buildSceneClip(StoryImagePrompt $scene, float $duration, string $workDir, int $index): string
    {
        $rawPath = $this->buildRawVisual($scene, $duration, $workDir, $index);

        $segments = $this->buildLineSegments($scene->narration ?? '', $duration);

        if (empty($segments)) {
            return $rawPath; // no narration text yet — nothing to caption
        }

        $srtPath = "{$workDir}/captions-{$index}.srt";
        file_put_contents($srtPath, $this->buildSrt($segments));

        $outPath = "{$workDir}/scene-{$index}.mp4";

        // subtitles= needs its own colon/comma escaping inside the filter
        // graph — sidestepped entirely by keeping every path in $workDir
        // to plain alphanumerics (see assemble()'s Str::random directory
        // name), so nothing here ever needs escaping.
        $result = Process::timeout(120)->run([
            'ffmpeg', '-y', '-i', $rawPath,
            '-vf', "subtitles={$srtPath}:force_style='" . self::CAPTION_STYLE . "'",
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'veryfast',
            $outPath,
        ]);

        if ($result->failed()) {
            throw new RuntimeException("ffmpeg caption burn-in failed for scene {$scene->id}: " . $result->errorOutput());
        }

        return $outPath;
    }

    /**
     * The scene's raw visual (no captions yet), normalized to a common
     * resolution/fps/pixel format so every scene concatenates cleanly.
     */
    protected function buildRawVisual(StoryImagePrompt $scene, float $duration, string $workDir, int $index): string
    {
        $scale = self::WIDTH . ':' . self::HEIGHT; // colon syntax — correct for scale=/pad=
        $vf = "scale={$scale}:force_original_aspect_ratio=decrease,pad={$scale}:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=" . self::FPS;

        $video = $scene->video; // StoryImagePrompt::video() -> hasOneThrough VideoPrompt -> Video

        if ($video && $video->video_url) {
            $outPath = "{$workDir}/raw-{$index}.mp4";
            $sourcePath = $this->download($video->video_url, "{$workDir}/source-video-{$index}.mp4");

            // -stream_loop -1 loops the source indefinitely; -t caps the
            // output at $duration either way, so this handles a source
            // clip that's shorter OR longer than the target duration with
            // one command. A real motion clip already has its own
            // movement, so — unlike the still-image branch below — it's
            // rendered as one continuous piece rather than chopped into
            // repeated beats; there's no "reuse the image" concern here.
            $result = Process::timeout(120)->run([
                'ffmpeg', '-y', '-stream_loop', '-1', '-i', $sourcePath,
                '-t', (string) $duration,
                '-vf', $vf,
                '-an', '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'veryfast',
                $outPath,
            ]);

            if ($result->failed()) {
                throw new RuntimeException("ffmpeg motion-clip build failed for scene {$scene->id}: " . $result->errorOutput());
            }

            return $outPath;
        }

        // Still image: render one short Ken Burns "beat" per
        // SEGMENT_SECONDS chunk, reusing the same source image each time,
        // then concatenate the beats into one scene-length clip. A scene
        // held for longer than one beat reads as a sequence of fresh
        // shots instead of one long, increasingly extreme pan.
        $imageUrl = $scene->image_generated_url_quality ?: $scene->image_generated_url;
        $sourcePath = $this->download($imageUrl, "{$workDir}/source-image-{$index}.jpg");

        $beatCount = max(1, (int) ceil($duration / self::SEGMENT_SECONDS));
        $beatPaths = [];

        for ($b = 0; $b < $beatCount; $b++) {
            $isLast = $b === $beatCount - 1;
            $beatDuration = $isLast ? ($duration - self::SEGMENT_SECONDS * $b) : self::SEGMENT_SECONDS;
            $beatDuration = max(0.5, $beatDuration); // guard against a near-zero final beat

            $beatPath = "{$workDir}/beat-{$index}-{$b}.mp4";
            $frames = max(1, (int) round($beatDuration * self::FPS));

            // WxH syntax (with 'x') — zoompan's `s` option, unlike
            // scale=/pad= above, does not accept the colon form. See class
            // doc-comment: this was caught by actually running it.
            $sizeWH = self::WIDTH . 'x' . self::HEIGHT;

            // Each beat starts its zoom a little further in than the last
            // (capped) so consecutive beats feel like a progression on the
            // same image rather than an identical repeated animation.
            $zoomStart = min(1.00 + ($b * 0.02), 1.20);
            $zoomEnd = min($zoomStart + 0.03, 1.25);
            $zoompan = "zoompan=z='min(max({$zoomStart},zoom+0.0008),{$zoomEnd})':d={$frames}:s={$sizeWH}:fps=" . self::FPS;

            $result = Process::timeout(120)->run([
                'ffmpeg', '-y', '-loop', '1', '-i', $sourcePath,
                '-vf', "{$zoompan},setsar=1",
                '-t', (string) $beatDuration,
                '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'veryfast',
                $beatPath,
            ]);

            if ($result->failed()) {
                throw new RuntimeException("ffmpeg Ken Burns beat build failed for scene {$scene->id}, beat {$b}: " . $result->errorOutput());
            }

            $beatPaths[] = $beatPath;
        }

        return $beatCount === 1 ? $beatPaths[0] : $this->concat($beatPaths, $workDir, "raw-{$index}.mp4", video: true);
    }

    /**
     * Splits narration text into ~SEGMENT_SECONDS caption beats,
     * proportioning word count by each beat's share of the total
     * duration. There's no word-level timing from TTS to work from, so
     * this is a reasonable approximation rather than exact sync — good
     * enough for readable, roughly-paced captions without needing forced
     * alignment.
     *
     * DIALOGUE extension point: this takes one (text, duration) pair
     * today because that's one narration line. Multi-speaker dialogue
     * would call this once per line (each with its own duration from its
     * own audio clip) and merge the resulting segment arrays in order,
     * optionally prefixing each line's segments with "SPEAKER: ".
     *
     * @return array<int, array{start: float, duration: float, text: string}>
     */
    protected function buildLineSegments(string $text, float $totalDuration): array
    {
        $text = trim($text);

        if ($text === '' || $totalDuration <= 0) {
            return [];
        }

        $words = preg_split('/\s+/', $text) ?: [];
        $wordCount = count($words);

        $beatCount = max(1, (int) ceil($totalDuration / self::SEGMENT_SECONDS));
        $segments = [];
        $elapsed = 0.0;
        $wordIndex = 0;

        for ($i = 0; $i < $beatCount; $i++) {
            $isLast = $i === $beatCount - 1;
            $beatDuration = $isLast ? ($totalDuration - $elapsed) : self::SEGMENT_SECONDS;
            $beatDuration = max(0.1, $beatDuration);

            $share = $beatDuration / $totalDuration;
            $wordsForBeat = $isLast
                ? ($wordCount - $wordIndex)
                : max(1, (int) round($wordCount * $share));
            $wordsForBeat = max(0, min($wordsForBeat, $wordCount - $wordIndex));

            $beatText = implode(' ', array_slice($words, $wordIndex, $wordsForBeat));
            $wordIndex += $wordsForBeat;

            if ($beatText !== '') {
                $segments[] = ['start' => $elapsed, 'duration' => $beatDuration, 'text' => $beatText];
            }

            $elapsed += $beatDuration;
        }

        return $segments;
    }

    /** @param array<int, array{start: float, duration: float, text: string}> $segments */
    protected function buildSrt(array $segments): string
    {
        $lines = [];

        foreach ($segments as $i => $segment) {
            $start = $this->srtTimestamp($segment['start']);
            $end = $this->srtTimestamp($segment['start'] + $segment['duration']);

            $lines[] = (string) ($i + 1);
            $lines[] = "{$start} --> {$end}";
            $lines[] = $segment['text'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    protected function srtTimestamp(float $seconds): string
    {
        $ms = (int) round(($seconds - floor($seconds)) * 1000);
        $whole = (int) floor($seconds);
        $h = intdiv($whole, 3600);
        $m = intdiv($whole % 3600, 60);
        $s = $whole % 60;

        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms);
    }

    /** Concat demuxer join — video segments or audio clips, same approach either way. */
    protected function concat(array $paths, string $workDir, string $outFilename, bool $video): string
    {
        $listPath = "{$workDir}/" . Str::random(6) . ($video ? '-videos.txt' : '-audios.txt');
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
        // See App\Support\BinaryDownloader — this method downloads
        // whatever URL a scene points at (image, per-scene video,
        // narration audio), so it's the single highest-exposure spot for
        // the Agnes CDN cURL-error-61/Brotli issue in this app.
        $bytes = BinaryDownloader::get($url);
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
