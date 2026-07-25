<?php

namespace App\Console\Commands;

use App\Models\StoryImagePrompt;
use App\Models\Video;
use Illuminate\Console\Command;

/**
 * Scheduler 5 — the dashboard lets users pick a future scheduled_at for a
 * draft image/video. This flips anything due into 'published' so the feed
 * label updates without the user needing to come back and do it manually.
 */
class PublishDueContent extends Command
{
    protected $signature = 'content:publish-due';
    protected $description = 'Publish any scheduled images/videos whose scheduled_at time has arrived.';

    public function handle(): int
    {
        $images = StoryImagePrompt::dueForPublishing()->update([
            'status' => StoryImagePrompt::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $videos = Video::where('status', Video::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->update([
                'status' => Video::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

        if ($images + $videos > 0) {
            $this->info("Published {$images} images and {$videos} videos.");
        }

        return self::SUCCESS;
    }
}
