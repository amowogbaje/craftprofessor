<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\StoryImagePrompt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupPublishedImages extends Command
{
    protected $signature = 'images:cleanup-published
                            {--dry-run : Show what would be deleted without deleting}
                            {--force : Skip confirmation}';

    protected $description = 'Delete original quality images after they have been pinned to Pinterest, keeping only the optimized versions.';

    public function handle(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will permanently delete original quality images. Continue?')) {
                return self::SUCCESS;
            }
        }

        $dryRun = $this->option('dry-run');

        $models = [
            [
                'class' => Character::class,
                'column' => 'image_url_quality',
            ],
            [
                'class' => StoryImagePrompt::class,
                'column' => 'image_generated_url_quality',
            ],
        ];

        $totalProcessed = 0;
        $totalDeleted = 0;
        $totalFailed = 0;

        foreach ($models as $config) {
            $modelClass = $config['class'];
            $column = $config['column'];
            $modelName = class_basename($modelClass);

            $this->newLine();
            $this->info("Processing {$modelName}...");

            $modelClass::query()
                ->whereNotNull('pinterest_pin_id')
                ->whereNotNull($column)
                ->chunkById(100, function ($records) use (
                    $column,
                    $modelName,
                    $dryRun,
                    &$totalProcessed,
                    &$totalDeleted,
                    &$totalFailed
                ) {
                    foreach ($records as $record) {
                        $totalProcessed++;

                        try {
                            $url = $record->{$column};

                            $path = ltrim(parse_url($url, PHP_URL_PATH), '/');

                            // Remove the "storage/" prefix if present
                            if (str_starts_with($path, 'storage/')) {
                                $path = substr($path, strlen('storage/'));
                            }

                            if ($dryRun) {
                                $this->line("[DRY RUN] {$modelName} #{$record->id}: {$path}");
                                continue;
                            }

                            DB::transaction(function () use (
                                $record,
                                $column,
                                $path,
                                &$totalDeleted
                            ) {
                                $disk = Storage::disk('public');

                                // Delete the file if it exists
                                if ($disk->exists($path)) {
                                    $disk->delete($path);
                                }

                                // Clear the quality image reference
                                $record->update([
                                    $column => null,
                                ]);

                                $totalDeleted++;
                            });

                            $this->line("✓ {$modelName} #{$record->id}");
                        } catch (\Throwable $e) {
                            $totalFailed++;

                            Log::error('Failed cleaning published image', [
                                'model' => $modelName,
                                'id' => $record->id,
                                'column' => $column,
                                'error' => $e->getMessage(),
                            ]);

                            $this->error("✗ {$modelName} #{$record->id}: {$e->getMessage()}");
                        }
                    }
                });
        }

        $this->newLine();

        if ($dryRun) {
            $this->info("Dry run complete.");
            $this->info("Records that would be processed: {$totalProcessed}");
        } else {
            $this->info('Cleanup complete.');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Processed', $totalProcessed],
                    ['Deleted', $totalDeleted],
                    ['Failed', $totalFailed],
                ]
            );
        }

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}