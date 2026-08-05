<?php

namespace App\Console\Commands;

use App\Services\Causes\CauseBroadcastService;
use Illuminate\Console\Command;

/**
 * The cause equivalent of story:post-pinterest-pin — picks up every
 * CauseBroadcast whose scheduled_at (stored UTC, originally entered in
 * the scheduling user's own timezone) has arrived, and posts it through
 * the owning member's connected account via SocialContentRouter.
 */
class PostDueCauseBroadcasts extends Command
{
    protected $signature = 'causes:post-due {--limit=50}';
    protected $description = 'Post any Cause broadcasts whose scheduled time has arrived.';

    public function handle(CauseBroadcastService $broadcasts): int
    {
        $results = $broadcasts->publishDue((int) $this->option('limit'));

        if ($results->isEmpty()) {
            $this->info('No due cause broadcasts.');
            return self::SUCCESS;
        }

        foreach ($results as $broadcast) {
            $line = "Broadcast #{$broadcast->id} (cause #{$broadcast->cause_id}, {$broadcast->provider}): {$broadcast->status}";
            $broadcast->status === 'posted' ? $this->info($line) : $this->error("{$line} — {$broadcast->error}");
        }

        return self::SUCCESS;
    }
}
