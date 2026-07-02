<?php

namespace App\Console\Commands;

use App\Models\Story;
use App\Services\MediumService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Scheduler 1 — runs every 5 minutes.
 * Picks the first story with a null story_text and fetches it via MediumService.
 */
class FetchMediumStoryText extends Command
{
    protected $signature = 'story:fetch-medium-text';
    protected $description = 'Fetch story_text for the next story missing it, via Medium.';

    public function handle(MediumService $medium): int
    {
        $story = Story::missingText()->oldest('id')->first();

        if (!$story) {
            $this->info('No stories awaiting text.');
            return self::SUCCESS;
        }

        $this->info("Fetching text for story #{$story->id} ({$story->medium_link})");

        try {
            $text = $medium->fetchStoryText($story);
            $story->update([
                'story_text' => $text,
                'last_fetch_error' => null,
            ]);
            $this->info("Story #{$story->id} updated (" . strlen($text) . ' chars).');
        } catch (\Throwable $e) {
            Log::error('story:fetch-medium-text failed', [
                'story_id' => $story->id,
                'url' => $story->medium_link,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $story->update([
                'fetch_attempts' => $story->fetch_attempts + 1,
                'last_fetch_error' => Str::limit($e->getMessage(), 2000),
            ]);
            $this->error("Failed: {$e->getMessage()}");
        }

        return self::SUCCESS;
    }
}
