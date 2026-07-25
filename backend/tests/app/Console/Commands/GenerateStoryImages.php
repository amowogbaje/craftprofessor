<?php

namespace App\Console\Commands;

use App\Exceptions\UserGenerationLimitReached;
use App\Models\Character;
use App\Models\StoryImagePrompt;
use App\Services\ImageGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 *
 * Fairness rule: both queues are oldest-first, which used to mean that if
 * the oldest row belonged to a user who was rate-limited or out of coins,
 * every remaining iteration this run would re-select that exact same row,
 * fail the same way, and never advance — starving every other user queued
 * behind them for the whole run (and every run after, since nothing about
 * that row ever changes). $blockedUserIds tracks who's been turned away
 * this run so candidate selection skips straight past their rows instead.
 */
class GenerateStoryImages extends Command
{
    protected $signature = 'story:generate-images 
        {--limit=5 : Max images to generate this run}
        {--time-budget=50 : Max seconds this run should take}';

    /** User ids that hit a limit/coins wall this run — skip their rows for the rest of the run. */
    protected Collection $blockedUserIds;

    public function handle(ImageGeneratorService $service): int
    {
        $startedAt = microtime(true);
        $timeBudget = (int) $this->option('time-budget');
        $limit = (int) $this->option('limit');
        $this->blockedUserIds = collect();

        $imageCap = config('images.daily_image_cap');
        $spentToday = $this->imagesGeneratedToday();

        if ($spentToday >= $imageCap) {
            $this->info("Daily cost cap reached ({$spentToday}/{$imageCap} cents).");
            return self::SUCCESS;
        }

        $generated = 0;

        while ($generated < $limit) {
            if ((microtime(true) - $startedAt) > $timeBudget) {
                $this->info("Time budget exhausted, stopping at {$generated} images.");
                break;
            }

            if ($this->imagesGeneratedToday() >= $imageCap) {
                $this->info('Cost cap hit mid-run, stopping.');
                break;
            }

            $url = null;
            $character = $this->nextPortraitCharacter();

            try {
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
            } catch (UserGenerationLimitReached $e) {
                $this->blockedUserIds->push($e->userId);
                Log::info('GenerateStoryImages: user blocked this run, moving to the next one', [
                    'user_id' => $e->userId,
                    'reason' => $e->getMessage(),
                ]);
                continue; // re-loop: selection now excludes this user
            }

            if ($url) {
                $this->info("Generated {$type} image: {$url}");
                $generated++;
            }
        }

        if ($generated > 0) {
            $this->info("Run complete: {$generated} images generated.");
        }

        if ($this->blockedUserIds->isNotEmpty()) {
            $this->info('Skipped users this run (limit/coins): ' . $this->blockedUserIds->unique()->implode(', '));
        }

        return self::SUCCESS;
    }

    /** Oldest awaiting-portrait character, excluding users already blocked this run. */
    protected function nextPortraitCharacter(): ?Character
    {
        return Character::awaitingPortrait()
            ->when($this->blockedUserIds->isNotEmpty(), fn ($q) => $q->whereNotIn('user_id', $this->blockedUserIds->unique()))
            ->oldest('id')
            ->first();
    }

    protected function imagesGeneratedToday(): int
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
            ->when($this->blockedUserIds->isNotEmpty(), fn ($q) => $q->whereNotIn('user_id', $this->blockedUserIds->unique()))
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
