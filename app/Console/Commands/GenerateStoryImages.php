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
    protected $signature = 'story:generate-images 
        {--limit=25 : Max images to generate this run}
        {--time-budget=50 : Max seconds this run should take}';

    public function handle(ImageGeneratorService $service): int
    {
        $startedAt = microtime(true);
        $timeBudget = (int) $this->option('time-budget');
        $limit = (int) $this->option('limit');

        $costCap = config('images.daily_cost_cap_cents');
        $spentToday = $this->costGeneratedTodayCents();

        if ($spentToday >= $costCap) {
            $this->info("Daily cost cap reached ({$spentToday}/{$costCap} cents).");
            return self::SUCCESS;
        }

        $generated = 0;

        while ($generated < $limit) {
            if ((microtime(true) - $startedAt) > $timeBudget) {
                $this->info("Time budget exhausted, stopping at {$generated} images.");
                break;
            }

            if ($this->costGeneratedTodayCents() >= $costCap) {
                $this->info('Cost cap hit mid-run, stopping.');
                break;
            }

            $url = null;
            $character = Character::awaitingPortrait()->oldest('id')->first();
            
            if ($character) {
                $url = $service->generateCharacterImage($character);
                $type = 'Character';
            } else {
                $prompt = $this->nextReadyScenePrompt();
                if (!$prompt) {
                    break; // No more work to do, exit silently
                }
                $url = $service->generateImage($prompt);
                $type = 'Scene';
            }

            if ($url) {
                $this->info("Generated {$type} image: {$url}");
                $generated++;
            }
        }

        if ($generated > 0) {
            $this->info("Run complete: {$generated} images generated.");
        }
        
        return self::SUCCESS;
    }

    protected function costGeneratedTodayCents(): int
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
        return StoryImagePrompt::awaitingImage()
            ->oldest('id')
            ->get()
            ->first(function (StoryImagePrompt $prompt) {

                $ids = $prompt->main_character_ids ?? [];

                if (empty($ids)) {
                    return true;
                }

                return ! Character::whereIn('id', $ids)
                    ->whereNull('img_url')
                    ->exists();
            });
    }
}
