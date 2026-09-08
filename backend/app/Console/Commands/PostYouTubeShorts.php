<?php

namespace App\Console\Commands;

use App\Models\SocialPost;
use App\Models\StoryVideo;
use App\Services\SocialPlatforms\SocialPlatformManager;
use App\Services\SocialPlatforms\YouTubePlatform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The YouTube counterpart to PostPinterestStoryVideos — posts at most one
 * assembled StoryVideo per invocation, as a YouTube Short (see
 * YouTubePlatform::publishShort() / YouTubeShortsExportService), enforcing
 * its own daily cap (services.youtube.max_shorts_per_user_per_day, default
 * 1 — see YouTube.md and the project spec: "4 images + 1 video" per day).
 *
 * Deliberately its own cap/command rather than sharing Pinterest's: a
 * user can have YouTube connected and Pinterest not (or vice versa), and
 * each platform's quota/rate-limit story is unrelated to the other's.
 *
 * Only ever picks up StoryVideo rows that are status=ready and haven't
 * been posted to YouTube yet (posted_to_youtube=false) — same
 * "flagship post per finished story" semantics as the Pinterest video job,
 * just against a separate posted_to_youtube flag so the same StoryVideo
 * row can be posted to both platforms independently.
 */
class PostYouTubeShorts extends Command
{
    protected $signature = 'youtube:post-daily-short';
    protected $description = 'Post the next ready, un-posted assembled story video to YouTube as a Short, respecting its own daily cap.';

    private const CANDIDATE_BATCH_SIZE = 50;

    public function handle(SocialPlatformManager $platforms): int
    {
        $cap = (int) config('services.youtube.max_shorts_per_user_per_day', 1);
        $timezone = config('services.youtube.daily_cap_timezone', 'UTC');

        $startOfDay = now($timezone)->startOfDay();
        $endOfDay = $startOfDay->copy()->endOfDay();

        $candidates = StoryVideo::where('status', StoryVideo::STATUS_READY)
            ->where('posted_to_youtube', false)
            ->whereHas('user.socialAccounts', fn ($q) => $q->where('provider', 'youtube'))
            ->oldest('generated_at')
            ->limit(self::CANDIDATE_BATCH_SIZE)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No story videos awaiting a YouTube post (or no user has YouTube connected).');
            return self::SUCCESS;
        }

        $userIds = $candidates->pluck('user_id')->filter()->unique()->values();

        $postedTodayByUser = SocialPost::query()
            ->where('platform', 'youtube')
            ->where('status', 'posted')
            ->whereNotNull('story_video_id')
            ->whereIn('user_id', $userIds)
            ->whereBetween('posted_at', [
                $startOfDay->clone()->utc(),
                $endOfDay->clone()->utc(),
            ])
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $next = $candidates->first(
            fn (StoryVideo $video) => ($postedTodayByUser[$video->user_id] ?? 0) < $cap
        );

        if (!$next) {
            $this->info("Every user with a ready story video has already hit today's YouTube cap ({$cap}/day). Nothing to post this run.");
            return self::SUCCESS;
        }

        $alreadyPostedToday = $postedTodayByUser[$next->user_id] ?? 0;
        $this->info("Posting story video #{$next->id} (story #{$next->story_id}, user #{$next->user_id}) to YouTube as a Short, {$alreadyPostedToday}/{$cap} posted today so far.");

        try {
            /** @var YouTubePlatform $youtube */
            $youtube = $platforms->forUser($next->user_id, 'youtube');
            $result = $youtube->publishShort($next);

            if ($result->success) {
                $next->update([
                    'posted_to_youtube' => true,
                    'youtube_posted_at' => now(),
                    'youtube_video_id' => $result->externalPostId,
                    'last_youtube_error' => null,
                ]);
                $this->info("  ✓ posted as YouTube video {$result->externalPostId}");
            } else {
                $next->update(['last_youtube_error' => Str::limit($result->error ?? 'Unknown failure.', 2000)]);
                $this->error("  ✗ {$result->error}");
            }
        } catch (\Throwable $e) {
            Log::channel('youtube')->error('youtube:post-daily-short failed', [
                'story_video_id' => $next->id,
                'story_id' => $next->story_id,
                'user_id' => $next->user_id,
                'error' => $e->getMessage(),
            ]);
            $next->update(['last_youtube_error' => Str::limit($e->getMessage(), 2000)]);
            $this->error("Failed: {$e->getMessage()}");
            report($e);
        }

        return self::SUCCESS;
    }
}
