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

            $character = Character::awaitingPortrait()->oldest('id')->first();
            if ($character) {
                $service->generateCharacterImage($character);
                $generated++;
                continue;
            }

            $prompt = $this->nextReadyScenePrompt();
            if (!$prompt) {
                $this->info('Nothing left to generate right now.');
                break;
            }

            $service->generateImage($prompt);
            $generated++;
        }

        $this->info("Run complete: {$generated} images generated.");
        return self::SUCCESS;
    }
}
