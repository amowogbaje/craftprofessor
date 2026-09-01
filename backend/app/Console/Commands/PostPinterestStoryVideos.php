<?php

namespace App\Console\Commands;

use App\Models\SocialPost;
use App\Models\StoryVideo;
use App\Services\SocialPlatforms\PinterestPlatform;
use App\Services\SocialPlatforms\SocialPlatformManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The story-video counterpart to PostPinterestPins — posts at most one
 * assembled StoryVideo per invocation (the narrated-and-captioned "mixed
 * copy", see StoryVideoAssemblyService and PinterestPlatform::publishStoryVideo()),
 * enforcing its own, separate daily cap
 * (services.pinterest.max_story_videos_per_user_per_day, default 3) —
 * deliberately not sharing PostPinterestPins' per-image cap, since a story
 * video is a one-off flagship post per finished story rather than
 * recurring per-scene content.
 *
 * Only ever picks up StoryVideo rows that are status=ready and haven't
 * been posted yet (posted_to_pinterest=false) — never a per-scene asset,
 * and never a story video that's still mid-assembly or failed.
 */
class PostPinterestStoryVideos extends Command
{
    protected $signature = 'story:post-pinterest-story-video';
    protected $description = 'Post the next ready, un-posted assembled story video to Pinterest, respecting its own daily cap.';

    private const CANDIDATE_BATCH_SIZE = 50;

    public function handle(SocialPlatformManager $platforms): int
    {
        $cap = (int) config('services.pinterest.max_story_videos_per_user_per_day', 3);
        $timezone = config('services.pinterest.daily_cap_timezone', 'UTC');

        $startOfDay = now($timezone)->startOfDay();
        $endOfDay = $startOfDay->copy()->endOfDay();

        $candidates = StoryVideo::where('status', StoryVideo::STATUS_READY)
            ->where('posted_to_pinterest', false)
            ->oldest('generated_at')
            ->limit(self::CANDIDATE_BATCH_SIZE)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No story videos awaiting a Pinterest post.');
            return self::SUCCESS;
        }

        $userIds = $candidates->pluck('user_id')->filter()->unique()->values();

        $postedTodayByUser = SocialPost::query()
            ->where('platform', 'pinterest')
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
            $this->info("Every user with a ready story video has already hit today's cap ({$cap}/day). Nothing to post this run.");
            return self::SUCCESS;
        }

        $alreadyPostedToday = $postedTodayByUser[$next->user_id] ?? 0;
        $this->info("Posting story video #{$next->id} (story #{$next->story_id}, user #{$next->user_id}, {$alreadyPostedToday}/{$cap} posted today so far).");

        try {
            /** @var PinterestPlatform $pinterest */
            $pinterest = $platforms->forUser($next->user_id, 'pinterest');
            $result = $pinterest->publishStoryVideo($next);

            if ($result->success) {
                $next->update([
                    'posted_to_pinterest' => true,
                    'pinterest_posted_at' => now(),
                    'pinterest_pin_id' => $result->externalPostId,
                    'last_pinterest_error' => null,
                ]);
                $this->info("  ✓ posted as pin {$result->externalPostId}");
            } else {
                $next->update(['last_pinterest_error' => Str::limit($result->error ?? 'Unknown failure.', 2000)]);
                $this->error("  ✗ {$result->error}");
            }
        } catch (\Throwable $e) {
            Log::error('story:post-pinterest-story-video failed', [
                'story_video_id' => $next->id,
                'story_id' => $next->story_id,
                'user_id' => $next->user_id,
                'error' => $e->getMessage(),
            ]);
            $next->update(['last_pinterest_error' => Str::limit($e->getMessage(), 2000)]);
            $this->error("Failed: {$e->getMessage()}");
            report($e);
        }

        return self::SUCCESS;
    }
}
