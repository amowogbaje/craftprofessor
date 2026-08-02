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
 * — see PinterestPlatform::publishToBoards()), and enforces a hard
 * per-user daily cap (services.pinterest.max_pins_per_user_per_day,
 * default 5) itself rather than relying solely on how often it's
 * scheduled.
 *
 * The cap counts actual PINS (rows in social_posts, platform=pinterest,
 * status=posted) rather than images — since one image can now produce up
 * to 3 pins (one per board), that's what actually needs bounding to keep
 * this from posting unbounded volume.
 *
 * Why the scheduling-frequency note still matters: routes/console.php
 * fires this command roughly every 5 minutes across two overnight windows
 * — up to ~48 invocations/day. Each run skips any user who has already
 * hit their cap for "today" (in services.pinterest.daily_cap_timezone)
 * and posts the oldest still-eligible image belonging to a user under
 * cap. If every user with a ready image has hit their cap, the run does
 * nothing.
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
    protected $description = 'Post the next ready image as Pinterest pin(s) (up to 3 boards), respecting the per-user daily pin cap.';

    /** How many oldest-ready candidates to consider before giving up for this run. */
    private const CANDIDATE_BATCH_SIZE = 100;

    public function handle(SocialPlatformManager $platforms): int
    {
        $cap = (int) config('services.pinterest.max_pins_per_user_per_day', 5);
        $timezone = config('services.pinterest.daily_cap_timezone', 'UTC');

        $startOfDay = now($timezone)->startOfDay();
        $endOfDay = $startOfDay->copy()->endOfDay();

        $candidates = StoryImagePrompt::awaitingPinterestPost()
            ->oldest('generated_at')
            ->limit(self::CANDIDATE_BATCH_SIZE)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No images awaiting a Pinterest post.');
            return self::SUCCESS;
        }

        $userIds = $candidates->pluck('user_id')->filter()->unique()->values();

        // One aggregate query for how many PINS (not images — a single
        // image can produce up to 3) each of these users has already
        // posted today.
        $postedTodayByUser = SocialPost::query()
            ->where('platform', 'pinterest')
            ->where('status', 'posted')
            ->whereIn('user_id', $userIds)
            ->whereBetween('posted_at', [
                $startOfDay->clone()->utc(),
                $endOfDay->clone()->utc(),
            ])
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $next = $candidates->first(
            fn (StoryImagePrompt $prompt) => ($postedTodayByUser[$prompt->user_id] ?? 0) < $cap
        );

        if (!$next) {
            $this->info("Every user with a ready image has already hit today's Pinterest cap ({$cap} pins/day). Nothing to post this run.");
            return self::SUCCESS;
        }

        $alreadyPostedToday = $postedTodayByUser[$next->user_id] ?? 0;
        $this->info("Posting prompt #{$next->id} to Pinterest (user #{$next->user_id}, {$alreadyPostedToday}/{$cap} pins posted today so far).");

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
