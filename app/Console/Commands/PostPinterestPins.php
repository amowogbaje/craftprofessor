<?php

namespace App\Console\Commands;

use App\Models\StoryImagePrompt;
use App\Services\PinterestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Scheduler 4 — posts one Pin per invocation. Scheduled twice daily (see
 * routes/console.php) so exactly 2 pins go out per day, at fixed times.
 */
class PostPinterestPins extends Command
{
    protected $signature = 'story:post-pinterest-pin';
    protected $description = 'Post the next ready image as a Pinterest pin.';

    public function handle(PinterestService $pinterest): int
    {
        $next = StoryImagePrompt::awaitingPinterestPost()->oldest('generated_at')->first();

        if (!$next) {
            $this->info('No images awaiting a Pinterest post.');
            return self::SUCCESS;
        }

        $this->info("Posting prompt #{$next->id} to Pinterest.");

        try {
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
