<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\StoryImagePrompt;
use App\Services\ImageGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler 3 — checks how many images were generated today (character
 * portraits + story scenes count together against one shared daily cap,
 * since both draw on the same Gemini free-tier quota); if under the cap,
 * generates the next one.
 *
 * Ordering rule: a character's portrait is ALWAYS generated before any
 * story scene that depends on it, so scene generation has a real reference
 * image to work from instead of inventing the character's look fresh.
 */
class GenerateStoryImages extends Command
{
    protected $signature = 'story:generate-images {--limit=3 : Max images to generate per day}';
    protected $description = 'Generate up to N images per day, character portraits first.';

    public function handle(ImageGeneratorService $service): int
    {
        $limit = (int) $this->option('limit');

        $generatedToday = $this->countGeneratedToday();

        if ($generatedToday >= $limit) {
            $this->info("Daily image cap reached ({$generatedToday}/{$limit}).");
            Log::info('story:generate-images — daily cap reached', ['generated_today' => $generatedToday, 'limit' => $limit]);
            return self::SUCCESS;
        }

        // Priority 1: any character with a portrait prompt but no image yet.
        $character = Character::awaitingPortrait()->oldest('id')->first();

        if ($character) {
            $this->info("Generating portrait for character #{$character->id} ({$character->name}) — today {$generatedToday}/{$limit}");
            $ok = $service->generateCharacterImage($character);

            $ok ? $this->info("Character #{$character->id}: portrait generated.")
                : $this->error("Character #{$character->id}: portrait generation failed, will retry.");

            return self::SUCCESS;
        }

        // Priority 2: next scene prompt whose characters (if any) are all ready.
        $prompt = $this->nextReadyScenePrompt();

        if (!$prompt) {
            $this->info('No portraits or ready scene prompts to generate right now.');
            return self::SUCCESS;
        }

        $this->info("Generating scene image for prompt #{$prompt->id} — today {$generatedToday}/{$limit}");
        $ok = $service->generateImage($prompt);

        $ok ? $this->info("Prompt #{$prompt->id}: image generated.")
            : $this->error("Prompt #{$prompt->id}: generation failed, will retry.");

        return self::SUCCESS;
    }

    protected function countGeneratedToday(): int
    {
        $today = [Carbon::today(), Carbon::tomorrow()];

        $characterCount = Character::whereNotNull('img_url')->whereBetween('generated_at', $today)->count();
        $sceneCount = StoryImagePrompt::whereNotNull('image_generated_url')->whereBetween('generated_at', $today)->count();

        return $characterCount + $sceneCount;
    }

    /**
     * Scans pending scene prompts (oldest first) and returns the first one
     * whose referenced characters all already have a generated img_url.
     * Prompts whose characters aren't ready yet are skipped for this run —
     * they'll naturally become eligible once their portraits finish.
     */
    protected function nextReadyScenePrompt(): ?StoryImagePrompt
    {
        $candidates = StoryImagePrompt::awaitingImage()->oldest('id')->limit(50)->get();

        foreach ($candidates as $candidate) {
            $ids = $candidate->main_character_ids ?? [];

            if (empty($ids)) {
                return $candidate;
            }

            $unready = Character::whereIn('id', $ids)->whereNull('img_url')->exists();

            if (!$unready) {
                return $candidate;
            }
        }

        return null;
    }
}
