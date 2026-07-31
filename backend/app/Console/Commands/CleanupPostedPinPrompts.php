<?php

namespace App\Console\Commands;

use App\Models\StoryImagePrompt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * php artisan images:cleanup-posted-prompts
 * php artisan images:cleanup-posted-prompts --days=14 --dry-run
 *
 * Once a Pin has been live for a while there's no reason to keep hosting
 * the image or the prompt row that generated it — this deletes both, for
 * any StoryImagePrompt whose pinterest_posted_at is more than --days
 * (default 10) in the past.
 *
 * Note this is a different job from `images:cleanup-published`, which
 * runs right after a pin goes out and only strips the *original-quality*
 * image file, keeping the row and the optimized image around. This
 * command runs much later and removes the whole prompt + whatever image
 * file(s) are still attached to it.
 *
 * Safety: by default, prompts with an associated video (VideoPrompt or
 * Video) are skipped rather than deleted, since video_prompts/videos both
 * cascade-delete when their story_image_prompt is deleted — pass
 * --include-videos to opt into deleting those too.
 */
class CleanupPostedPinPrompts extends Command
{
    protected $signature = 'images:cleanup-posted-prompts
                            {--days=10 : Delete prompts posted to Pinterest more than this many days ago}
                            {--dry-run : Show what would be deleted without deleting}
                            {--force : Skip the confirmation prompt}
                            {--include-videos : Also delete prompts that have a generated video (deletes the video too)}';

    protected $description = 'Delete images + prompt rows that were posted to Pinterest more than N days ago.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $includeVideos = $this->option('include-videos');
        $cutoff = now()->subDays($days);

        if (!$dryRun && !$this->option('force')) {
            if (!$this->confirm("This will permanently delete images and prompt rows posted to Pinterest before {$cutoff->toDateTimeString()}. Continue?")) {
                return self::SUCCESS;
            }
        }

        $query = StoryImagePrompt::query()
            ->where('posted_to_pinterest', true)
            ->whereNotNull('pinterest_posted_at')
            ->where('pinterest_posted_at', '<=', $cutoff);

        if (!$includeVideos) {
            $query->whereDoesntHave('videoPrompt');
        }

        $totalProcessed = 0;
        $totalDeleted = 0;
        $totalSkipped = 0;
        $totalFailed = 0;

        $query->chunkById(100, function ($prompts) use (
            $dryRun,
            &$totalProcessed,
            &$totalDeleted,
            &$totalSkipped,
            &$totalFailed
        ) {
            foreach ($prompts as $prompt) {
                $totalProcessed++;

                try {
                    if ($dryRun) {
                        $this->line("[DRY RUN] StoryImagePrompt #{$prompt->id} (posted {$prompt->pinterest_posted_at->toDateString()}) would be deleted.");
                        continue;
                    }

                    DB::transaction(function () use ($prompt, &$totalDeleted) {
                        foreach (['image_generated_url', 'image_generated_url_quality'] as $column) {
                            $this->deleteStoredFile($prompt->{$column});
                        }

                        // cascadeOnDelete() on video_prompts/videos handles
                        // those rows if --include-videos let us get here
                        // with one attached.
                        $prompt->delete();

                        $totalDeleted++;
                    });

                    $this->line("✓ Deleted StoryImagePrompt #{$prompt->id}");
                } catch (\Throwable $e) {
                    $totalFailed++;

                    Log::error('images:cleanup-posted-prompts failed', [
                        'story_image_prompt_id' => $prompt->id,
                        'error' => $e->getMessage(),
                    ]);

                    $this->error("✗ StoryImagePrompt #{$prompt->id}: {$e->getMessage()}");
                }
            }
        });

        if (!$includeVideos) {
            $totalSkipped = StoryImagePrompt::query()
                ->where('posted_to_pinterest', true)
                ->whereNotNull('pinterest_posted_at')
                ->where('pinterest_posted_at', '<=', $cutoff)
                ->whereHas('videoPrompt')
                ->count();

            if ($totalSkipped > 0) {
                $this->newLine();
                $this->warn("Skipped {$totalSkipped} prompt(s) that have a generated video — pass --include-videos to delete those too.");
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info('Dry run complete.');
            $this->info("Records that would be deleted: {$totalProcessed}");
        } else {
            $this->info('Cleanup complete.');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Processed', $totalProcessed],
                    ['Deleted', $totalDeleted],
                    ['Skipped (has video)', $totalSkipped],
                    ['Failed', $totalFailed],
                ]
            );
        }

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function deleteStoredFile(?string $url): void
    {
        if (!$url) {
            return;
        }

        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        $disk = Storage::disk('public');

        if ($path !== '' && $disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
