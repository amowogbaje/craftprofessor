<?php

namespace App\Console\Commands;

use App\Models\StoryImagePrompt;
use App\Services\PinterestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Scheduler 3 — posts at most one Pin per invocation, and enforces a hard
 * per-user daily cap (services.pinterest.max_pins_per_user_per_day,
 * default 5) itself rather than relying solely on how often it's
 * scheduled.
 *
 * Why this matters: routes/console.php fires this command roughly every 5
 * minutes across two overnight windows — up to ~48 invocations/day. Before
 * this cap existed, every invocation just grabbed the single globally
 * oldest awaiting-post image regardless of who owned it, so a user with a
 * large backlog could absorb every single slot for the day (and every
 * user combined could get far more than 5 pins/day) — effectively
 * unbounded posting. Now: each run skips any user who has already hit
 * their cap for "today" (in services.pinterest.daily_cap_timezone) and
 * posts the oldest still-eligible image belonging to a user under cap. If
 * every user with a ready image has hit their cap, the run does nothing.
 *
 * Also posts through the *owning* user's own connected Pinterest account
 * (PinterestService::forUser()) rather than a single global/static token —
 * each account's token is checked (and refreshed if needed) right before
 * use, on top of the proactive pinterest:refresh-tokens run scheduled
 * ahead of this command.
 */
class PostPinterestPins extends Command
{
    protected $signature = 'story:post-pinterest-pin';
    protected $description = 'Post the next ready image as a Pinterest pin, respecting the per-user daily cap.';

    /** How many oldest-ready candidates to consider before giving up for this run. */
    private const CANDIDATE_BATCH_SIZE = 100;

    public function handle(): int
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

        // One aggregate query for how many pins each of these users has
        // already posted today, instead of a count-per-candidate.
        $postedTodayByUser = StoryImagePrompt::query()
            ->where('posted_to_pinterest', true)
            ->whereIn('user_id', $userIds)
            ->whereBetween('pinterest_posted_at', [
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
            $this->info("Every user with a ready image has already hit today's Pinterest cap ({$cap}/day). Nothing to post this run.");
            return self::SUCCESS;
        }

        $alreadyPostedToday = $postedTodayByUser[$next->user_id] ?? 0;
        $this->info("Posting prompt #{$next->id} to Pinterest (user #{$next->user_id}, {$alreadyPostedToday}/{$cap} posted today so far).");

        try {
            $pinterest = PinterestService::forUser($next->user_id);
            $pinId = $pinterest->postPin($next);

            $next->update([
                'posted_to_pinterest' => true,
                'pinterest_posted_at' => now(),
                'pinterest_pin_id' => $pinId,
                'last_pinterest_error' => null,
            ]);

            $this->info("Posted as pin {$pinId}.");
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
