<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * YouTube counterpart to 2026_08_27_000002_add_pinterest_tracking_to_story_videos.
 *
 * Two groups of columns:
 *  - posted_to_youtube / youtube_posted_at / youtube_video_id /
 *    last_youtube_error: same shape as the Pinterest tracking columns,
 *    read/written by PostYouTubeShorts (see that command).
 *  - shorts_video_url / shorts_duration_seconds: the trimmed-to-Shorts
 *    (<=180s, see YouTube.md) cut of video_url, cached here the first time
 *    it's produced (YouTubeShortsExportService) so a video that's re-posted,
 *    retried, or inspected later doesn't re-run ffmpeg trimming again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_videos', function (Blueprint $table) {
            $table->boolean('posted_to_youtube')->default(false)->after('last_pinterest_error');
            $table->timestamp('youtube_posted_at')->nullable()->after('posted_to_youtube');
            $table->string('youtube_video_id')->nullable()->after('youtube_posted_at');
            $table->text('last_youtube_error')->nullable()->after('youtube_video_id');

            $table->string('shorts_video_url')->nullable()->after('last_youtube_error');
            $table->decimal('shorts_duration_seconds', 8, 2)->nullable()->after('shorts_video_url');
        });
    }

    public function down(): void
    {
        Schema::table('story_videos', function (Blueprint $table) {
            $table->dropColumn([
                'posted_to_youtube',
                'youtube_posted_at',
                'youtube_video_id',
                'last_youtube_error',
                'shorts_video_url',
                'shorts_duration_seconds',
            ]);
        });
    }
};
