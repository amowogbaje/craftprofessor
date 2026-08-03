<?php

namespace App\Console\Commands;

use App\Exceptions\UserGenerationLimitReached;
use App\Models\Character;
use App\Models\Environment;
use App\Models\Prop;
use App\Models\StoryImagePrompt;
use App\Services\ImageGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler 3 — checks how many images were generated today (character
 * portraits + environment/prop references + story scenes all count
 * together against one shared daily cap, since they all draw on the same
 * Gemini free-tier quota); if under the cap, generates the next one.
 *
 * Ordering rule: a reference asset's portrait (character, environment, OR
 * prop) is ALWAYS generated before any story scene that depends on it, so
 * scene generation has real reference material to work from instead of
 * inventing the look fresh every time. Portrait candidates are picked
 * oldest-first *across all three asset types together*, not
 * character-queue-then-environment-queue-then-prop-queue — otherwise a
 * story with many characters could starve its own environments/props (and
 * therefore its own scenes) for a long time.
 *
 * Fairness rule: all queues are oldest-first, which used to mean that if
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

    /** Which model class + service method handles each reference-asset type. */
    private const ASSET_TYPES = [
        'Character' => ['model' => Character::class, 'method' => 'generateCharacterImage'],
        'Environment' => ['model' => Environment::class, 'method' => 'generateEnvironmentImage'],
        'Prop' => ['model' => Prop::class, 'method' => 'generatePropImage'],
    ];

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

            $success = null;
            [$asset, $type] = $this->nextPortraitAsset();

            try {
                if ($asset) {
                    $method = self::ASSET_TYPES[$type]['method'];
                    $success = $service->{$method}($asset);
                    $label = "{$type} portrait #{$asset->id} ({$asset->name})";
                } else {
                    $prompt = $this->nextReadyScenePrompt();
                    if (!$prompt) {
                        break; // No more work to do, exit silently
                    }
                    $success = $service->generateImage($prompt);
                    $type = 'Scene';
                    $label = "Scene #{$prompt->id}";
                }
            } catch (UserGenerationLimitReached $e) {
                $this->blockedUserIds->push($e->userId);
                Log::info('GenerateStoryImages: user blocked this run, moving to the next one', [
                    'user_id' => $e->userId,
                    'reason' => $e->getMessage(),
                ]);
                continue; // re-loop: selection now excludes this user
            }

            if ($success) {
                $this->info("Generated {$label}");
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

    /**
     * The oldest awaiting-portrait row across characters, environments,
     * AND props together (not one type at a time), excluding users already
     * blocked this run.
     *
     * @return array{0: ?Model, 1: ?string} [$asset, $typeLabel]
     */
    protected function nextPortraitAsset(): array
    {
        $candidates = collect(self::ASSET_TYPES)
            ->map(function (array $config, string $type) {
                $asset = $config['model']::awaitingPortrait()
                    ->when($this->blockedUserIds->isNotEmpty(), fn ($q) => $q->whereNotIn('user_id', $this->blockedUserIds->unique()))
                    ->oldest('id')
                    ->first();

                return $asset ? [$asset, $type] : null;
            })
            ->filter();

        if ($candidates->isEmpty()) {
            return [null, null];
        }

        return $candidates->sort(fn ($a, $b) => $a[0]->id <=> $b[0]->id)->first();
    }

    protected function imagesGeneratedToday(): int
    {
        $today = [Carbon::today(), Carbon::tomorrow()];

        $characterCount = Character::whereNotNull('img_url')->whereBetween('generated_at', $today)->count();
        $environmentCount = Environment::whereNotNull('img_url')->whereBetween('generated_at', $today)->count();
        $propCount = Prop::whereNotNull('img_url')->whereBetween('generated_at', $today)->count();
        $sceneCount = StoryImagePrompt::whereNotNull('image_generated_url')->whereBetween('generated_at', $today)->count();

        return $characterCount + $environmentCount + $propCount + $sceneCount;
    }

    /**
     * Scans pending scene prompts (oldest first) and returns the first one
     * whose referenced characters, environments, AND props all already
     * have a generated img_url. Prompts with anything not ready yet are
     * skipped for this run — they'll naturally become eligible once their
     * portraits/references finish.
     */
    protected function nextReadyScenePrompt(): ?StoryImagePrompt
    {
        return StoryImagePrompt::awaitingImage()
            ->when($this->blockedUserIds->isNotEmpty(), fn ($q) => $q->whereNotIn('user_id', $this->blockedUserIds->unique()))
            ->oldest('id')
            ->get()
            ->first(fn (StoryImagePrompt $prompt) => $this->allReferencesReady($prompt));
    }

    protected function allReferencesReady(StoryImagePrompt $prompt): bool
    {
        foreach (self::ASSET_TYPES as $config) {
            $column = match ($config['model']) {
                Character::class => 'main_character_ids',
                Environment::class => 'main_environment_ids',
                Prop::class => 'main_prop_ids',
            };

            $ids = $prompt->{$column} ?? [];

            if (empty($ids)) {
                continue;
            }

            $notReady = $config['model']::whereIn('id', $ids)->whereNull('img_url')->exists();

            if ($notReady) {
                return false;
            }
        }

        return true;
    }
}
