<?php

namespace App\Console\Commands;

use App\Models\SocialPost;
use App\Models\StoryImagePrompt;
use App\Services\SocialPlatforms\PinterestPlatform;
use App\Services\SocialPlatforms\SocialPlatformManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Scheduler 3 — posts at most one image per invocation (to up to 3 boards
 * — see PinterestPlatform::publishToBoards()), enforcing a daily pin cap
 * per (user, story) rather than per user alone.
 *
 * Per-story, not per-user, because a story can set its own
 * pinterest_daily_pin_limit (Story::effectivePinterestDailyPinLimit()) —
 * a user running several stories can give one a faster daily cadence than
 * another, and each story's budget is tracked completely independently:
 * story A hitting its cap for the day never blocks story B's pins, even
 * for the same user. A story with no override just uses the account-wide
 * default (services.pinterest.max_pins_per_user_per_day), which is what
 * every story effectively had before this override existed.
 *
 * The cap counts actual PINS (rows in social_posts, platform=pinterest,
 * status=posted) rather than images — since one image can now produce up
 * to 3 pins (one per board), that's what actually needs bounding to keep
 * this from posting unbounded volume.
 *
 * Why the scheduling-frequency note still matters: routes/console.php
 * fires this command roughly every 5 minutes across two overnight windows
 * — up to ~48 invocations/day. Each run skips any (user, story) pair that
 * has already hit that story's cap for "today" (in
 * services.pinterest.daily_cap_timezone) and posts the oldest still-
 * eligible image belonging to a story under its own cap. If every story
 * with a ready image has hit its cap, the run does nothing.
 *
 * Posts through the *owning* user's own connected Pinterest account
 * (SocialPlatformManager::forUser()) rather than a single global/static
 * token — each account's token is checked (and refreshed if needed) right
 * before use, on top of the proactive pinterest:refresh-tokens run
 * scheduled ahead of this command.
 */
class PostPinterestPins extends Command
{
    protected $signature = 'story:post-pinterest-pin';
    protected $description = 'Post the next ready image as Pinterest pin(s) (up to 3 boards), respecting each story\'s own daily pin cap.';

    /** How many oldest-ready candidates to consider before giving up for this run. */
    private const CANDIDATE_BATCH_SIZE = 100;

    public function handle(SocialPlatformManager $platforms): int
    {
        $defaultCap = (int) config('services.pinterest.max_pins_per_user_per_day', 5);
        $timezone = config('services.pinterest.daily_cap_timezone', 'UTC');

        $startOfDay = now($timezone)->startOfDay();
        $endOfDay = $startOfDay->copy()->endOfDay();

        $candidates = StoryImagePrompt::awaitingPinterestPost()
            ->with('story:id,pinterest_daily_pin_limit')
            ->oldest('generated_at')
            ->limit(self::CANDIDATE_BATCH_SIZE)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No images awaiting a Pinterest post.');
            return self::SUCCESS;
        }

        $storyIds = $candidates->pluck('story_id')->filter()->unique()->values();

        // One aggregate query for how many PINS (not images — a single
        // image can produce up to 3) have already been posted today,
        // per (user, story) pair — not just per user, so each story's
        // budget is tracked independently.
        $postedTodayByStory = SocialPost::query()
            ->join('story_image_prompts', 'story_image_prompts.id', '=', 'social_posts.story_image_prompt_id')
            ->where('social_posts.platform', 'pinterest')
            ->where('social_posts.status', 'posted')
            ->whereIn('story_image_prompts.story_id', $storyIds)
            ->whereBetween('social_posts.posted_at', [
                $startOfDay->clone()->utc(),
                $endOfDay->clone()->utc(),
            ])
            ->selectRaw('story_image_prompts.story_id, count(*) as total')
            ->groupBy('story_image_prompts.story_id')
            ->pluck('total', 'story_id');

        $effectiveCap = fn (StoryImagePrompt $prompt) => $prompt->story?->effectivePinterestDailyPinLimit() ?? $defaultCap;

        $next = $candidates->first(
            fn (StoryImagePrompt $prompt) => !$prompt->story_id
                || ($postedTodayByStory[$prompt->story_id] ?? 0) < $effectiveCap($prompt)
        );

        if (!$next) {
            $this->info("Every story with a ready image has already hit its own Pinterest cap for today. Nothing to post this run.");
            return self::SUCCESS;
        }

        $cap = $effectiveCap($next);
        $alreadyPostedToday = $postedTodayByStory[$next->story_id] ?? 0;
        $this->info("Posting prompt #{$next->id} to Pinterest (user #{$next->user_id}, story #{$next->story_id}, {$alreadyPostedToday}/{$cap} pins posted for this story today so far).");

        try {
            /** @var PinterestPlatform $pinterest */
            $pinterest = $platforms->forUser($next->user_id, 'pinterest');
            $results = $pinterest->publishToBoards($next);

            $successes = $results->filter(fn ($r) => $r->success);
            $failures = $results->filter(fn ($r) => !$r->success);

            foreach ($successes as $result) {
                $this->info("  ✓ posted to board #{$result->pinterestBoardId} as pin {$result->externalPostId}");
            }
            foreach ($failures as $result) {
                $this->error("  ✗ board #{$result->pinterestBoardId}: {$result->error}");
            }

            if ($successes->isNotEmpty()) {
                // Keep the legacy single-value flags in sync (used by
                // awaitingPinterestPost()'s scope, link-click attribution,
                // etc.) — first successful board's pin id represents "this
                // image has been posted."
                $primary = $successes->first();

                $next->update([
                    'posted_to_pinterest' => true,
                    'pinterest_posted_at' => now(),
                    'pinterest_pin_id' => $primary->externalPostId,
                    'last_pinterest_error' => $failures->isNotEmpty()
                        ? 'Posted to ' . $successes->count() . '/' . $results->count() . ' boards — ' . $failures->pluck('error')->implode('; ')
                        : null,
                ]);
            } else {
                $error = $failures->pluck('error')->implode('; ') ?: 'Unknown failure.';
                $next->update(['last_pinterest_error' => Str::limit($error, 2000)]);
                $this->error("Failed on every board: {$error}");
            }
        } catch (\Throwable $e) {
            Log::error('story:post-pinterest-pin failed', [
                'story_image_prompt_id' => $next->id,
                'user_id' => $next->user_id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $next->update(['last_pinterest_error' => Str::limit($e->getMessage(), 2000)]);
            $this->error("Failed: {$e->getMessage()}");
            report($e);
        }

        return self::SUCCESS;
    }
}
