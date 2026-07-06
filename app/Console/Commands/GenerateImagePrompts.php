<?php

namespace App\Console\Commands;

use App\Models\Story;
use App\Services\ImageGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler 2 — picks the next story with story_text set and
 * prompt_generated = false, and generates its 10 image prompts +
 * Pinterest metadata via ImageGeneratorService::generatePromptsForStory().
 */
class GenerateImagePrompts extends Command
{
    protected $signature = 'story:generate-image-prompts';
    protected $description = 'Generate the 10 image prompts + pinterest metadata for the next ready story.';

    public function handle(): int
    {
        $remainingBudget = $this->remainingDailyBudget(); // returns count still allowed, both cap types
        $batchSize = min((int) $this->option('limit'), config('images.batch_size_per_run'), $remainingBudget);

        if ($batchSize <= 0) {
            $this->info('Daily cap reached.');
            return self::SUCCESS;
        }

        $dispatched = 0;

        // Portraits first, batched
        $characters = Character::awaitingPortrait()->oldest('id')->limit($batchSize)->get();
        foreach ($characters as $character) {
            GenerateCharacterImageJob::dispatch($character)->onQueue('images');
            $dispatched++;
        }

        $remaining = $batchSize - $dispatched;
        if ($remaining > 0) {
            $prompts = $this->readyScenePrompts($remaining);
            foreach ($prompts as $prompt) {
                GenerateSceneImageJob::dispatch($prompt)->onQueue('images');
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} image jobs.");
        return self::SUCCESS;
    }
}
