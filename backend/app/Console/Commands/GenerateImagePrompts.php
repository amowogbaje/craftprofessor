<?php

namespace App\Console\Commands;

use App\Models\Story;
use App\Services\ImageGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler 2 — picks the next story with story_text set and
 * prompt_generated = false, and generates its scene prompts (variable
 * count, decided per-story by ImagePromptAgent) + narration + Pinterest
 * metadata via ImageGeneratorService::generatePromptsForStory().
 */
class GenerateImagePrompts extends Command
{
    protected $signature = 'story:generate-image-prompts';
    protected $description = 'Generate the scene prompts + narration + pinterest metadata for the next ready story.';

    public function handle(ImageGeneratorService $service): int
    {
        // Episode order matters: episode 1 must generate its characters
        // before episode 2 runs, so episode 2 can reuse them instead of
        // recreating them. Standalone stories (episode_number null) have no
        // such dependency and are interleaved by creation order.
        $story = Story::readyForPrompts()
            ->orderByRaw('CASE WHEN episode_number IS NULL THEN 0 ELSE 1 END')
            ->orderBy('episode_number')
            ->oldest('id')
            ->first();

        if (!$story) {
            $this->info('No stories ready for prompt generation.');
            return self::SUCCESS;
        }

        $this->info("Generating prompts for story #{$story->id}");

        try {
            $service->generatePromptsForStory($story);
            $this->info("Story #{$story->id}: prompts generated and stored.");
        } catch (\Throwable $e) {
            Log::error('story:generate-image-prompts failed', [
                'story_id' => $story->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->error("Failed: {$e->getMessage()}");
            report($e);
        }

        return self::SUCCESS;
    }
}