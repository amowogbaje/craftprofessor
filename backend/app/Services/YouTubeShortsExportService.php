<?php

namespace App\Services;

use App\Models\StoryVideo;
use App\Support\BinaryDownloader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Produces the "trimmed/cropped version for YouTube Shorts" called for in
 * the project spec (section 1.2/1.3), sitting on top of
 * StoryVideoAssemblyService rather than duplicating it.
 *
 * StoryVideoAssemblyService already renders every story video vertical
 * (1080x1920, see its WIDTH/HEIGHT constants) — that's the harder half of
 * "Shorts-formatted." The only thing that can disqualify one of our own
 * assembled videos from the Shorts feed is length: Shorts currently allow
 * up to 180s (3 minutes, raised from 60s in Oct 2024 — see YouTube.md,
 * "Shorts constraints"), and a multi-scene story easily runs past that.
 *
 * So this service is intentionally small:
 *  - duration_seconds <= MAX_SHORT_SECONDS -> the full assembled video
 *    already qualifies, reuse video_url as-is (no re-encode, no quality
 *    loss).
 *  - otherwise -> stream-copy (no re-encode) the first MAX_SHORT_SECONDS
 *    into its own file. A story's scenes are told in order, so leading
 *    with the opening of the story is the right "trim," not a random or
 *    centered cut.
 * Result is cached on the StoryVideo row (shorts_video_url /
 * shorts_duration_seconds) so repeated calls (retried posts, the next
 * day's job re-checking an old row, etc.) don't re-run ffmpeg.
 */
class YouTubeShortsExportService
{
    public const MAX_SHORT_SECONDS = 180.0;

    public function export(StoryVideo $storyVideo): string
    {
        if ($storyVideo->shorts_video_url) {
            return $storyVideo->shorts_video_url;
        }

        if (!$storyVideo->video_url) {
            throw new RuntimeException("StoryVideo #{$storyVideo->id} has no video_url to trim.");
        }

        $duration = $storyVideo->duration_seconds;

        // No known duration, or already within the Shorts limit — nothing
        // to trim, the full video already qualifies as-is.
        if (!$duration || $duration <= self::MAX_SHORT_SECONDS) {
            $storyVideo->update([
                'shorts_video_url' => $storyVideo->video_url,
                'shorts_duration_seconds' => $duration,
            ]);

            return $storyVideo->video_url;
        }

        $workDir = storage_path('app/tmp/story-shorts-' . $storyVideo->id . '-' . Str::random(8));
        mkdir($workDir, 0755, true);

        try {
            $sourcePath = "{$workDir}/source.mp4";
            file_put_contents($sourcePath, BinaryDownloader::get($storyVideo->video_url));

            $outPath = "{$workDir}/short.mp4";

            // -ss before -i + stream copy: fast trim, no quality loss, no
            // re-encode. Good enough for a straight "first N seconds" cut;
            // if a scene-aware cut point is ever wanted instead, the scene
            // boundaries are on StoryImagePrompt::narration_audio_seconds
            // and this is the place to switch that in.
            $result = Process::timeout(120)->run([
                'ffmpeg', '-y',
                '-i', $sourcePath,
                '-t', (string) self::MAX_SHORT_SECONDS,
                '-c', 'copy',
                '-movflags', '+faststart',
                $outPath,
            ]);

            if ($result->failed()) {
                throw new RuntimeException("ffmpeg Shorts trim failed for story video #{$storyVideo->id}: " . $result->errorOutput());
            }

            $storagePath = "story-videos-shorts/{$storyVideo->story_id}/" . Str::random(8) . '.mp4';
            Storage::disk('public')->put($storagePath, file_get_contents($outPath));

            $url = Storage::disk('public')->url($storagePath);

            $storyVideo->update([
                'shorts_video_url' => $url,
                'shorts_duration_seconds' => self::MAX_SHORT_SECONDS,
            ]);

            Log::channel('youtube')->info('YouTubeShortsExportService: trimmed to Shorts length', [
                'story_video_id' => $storyVideo->id,
                'original_seconds' => $duration,
            ]);

            return $url;
        } finally {
            foreach (glob("{$workDir}/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($workDir);
        }
    }
}
