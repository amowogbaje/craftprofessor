<?php

namespace App\Services;

use App\Ai\Agents\ImageGeneratorAgent;
use App\Ai\Agents\ImagePromptAgent;
use App\Exceptions\UserGenerationLimitReached;
use App\Models\Character;
use App\Models\Environment;
use App\Models\Prop;
use App\Models\Story;
use App\Models\StoryImagePrompt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\RateLimitedException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageGeneratorService
{
    public function __construct(
        protected \App\Ai\Contracts\ImageProviderContract $imageProvider,
        protected \App\Services\WalletService $wallet,
        protected \App\Services\UsageLimitService $limits,
        protected \App\Services\ImageCaptionOverlayService $captionOverlay,
    ) {
    }

    public function generatePromptsForStory(Story $story): void
    {
        Log::info('ImageGeneratorService: generating prompts for story', ['story_id' => $story->id]);

        $existingCharacters = $story->knownCharacters()->get();
        $existingEnvironments = $story->knownEnvironments()->get();
        $existingProps = $story->knownProps()->get();

        $user = $story->user;
        $perSceneCost = (int) config('coins.costs.image_prompt');

        // The agent now decides the scene count per story (see
        // ImagePromptAgent::MIN_SCENES/MAX_SCENES) instead of always
        // returning exactly 10, so we can't know the real cost until after
        // generation. Gate on the worst case (MAX_SCENES) up front so we
        // never call the agent for a user who couldn't afford any result,
        // then debit only the actual count once we know it below.
        $worstCaseCost = $perSceneCost * ImagePromptAgent::MAX_SCENES;

        if ($user && !$this->wallet->canAfford($user, $worstCaseCost)) {
            Log::info('ImageGeneratorService: skipping prompt generation, insufficient coins', [
                'story_id' => $story->id, 'user_id' => $user->id, 'required' => $worstCaseCost,
            ]);
            return;
        }

        $debitedAmount = 0;

        try {
            $story->imagePrompts()->delete();

            $response = (new ImagePromptAgent($story, $existingCharacters, $existingEnvironments, $existingProps))
                ->prompt('Generate only the missing character/environment/prop prompts and new scene prompts.');

            $prompts = $response['prompts'] ?? [];

            if (count($prompts) === 0) {
                throw new \RuntimeException('ImagePromptAgent returned no prompts.');
            }

            $batchCost = $perSceneCost * count($prompts);

            if ($user) {
                if (!$this->wallet->canAfford($user, $batchCost)) {
                    Log::info('ImageGeneratorService: insufficient coins for actual scene count, skipping', [
                        'story_id' => $story->id, 'user_id' => $user->id,
                        'scene_count' => count($prompts), 'required' => $batchCost,
                    ]);
                    return;
                }

                $this->wallet->debit($user, $batchCost, 'image_prompt', $story);
                $debitedAmount = $batchCost;
            }

            $charactersByName = $this->reconcileAssets(
                Character::class, $response['characters'] ?? [], $story, $existingCharacters
            );
            $environmentsByName = $this->reconcileAssets(
                Environment::class, $response['environments'] ?? [], $story, $existingEnvironments
            );
            $propsByName = $this->reconcileAssets(
                Prop::class, $response['props'] ?? [], $story, $existingProps
            );

            foreach ($prompts as $index => $entry) {
                $characterIds = $this->resolveIds($entry['character_names'] ?? [], $charactersByName, $story, 'character');
                $environmentIds = $this->resolveIds($entry['environment_names'] ?? [], $environmentsByName, $story, 'environment');
                $propIds = $this->resolveIds($entry['prop_names'] ?? [], $propsByName, $story, 'prop');

                StoryImagePrompt::create([
                    'user_id' => $story->user_id,
                    'story_id' => $story->id,
                    'prompt' => $entry['prompt'],
                    'narration' => $entry['narration'] ?? null,
                    'scene_number' => $index + 1,
                    'caption' => $entry['caption'] ?? null,
                    'status' => StoryImagePrompt::STATUS_PUBLISHED,
                    'prompt_coin_cost' => (int) config('coins.costs.image_prompt'),
                    'main_character_ids' => $characterIds,
                    'main_environment_ids' => $environmentIds,
                    'main_prop_ids' => $propIds,
                    'pinterest_title' => $entry['pinterest_title'] ?? null,
                    'pinterest_description' => $entry['pinterest_description'] ?? null,
                    'pinterest_link' => $story->story_link
                        ? app(LinkTrackingService::class)->buildTrackedUrl($story->story_link, 'Pinterest')
                        : null,
                ]);
            }

            $story->update(['prompt_generated' => true]);

            Log::info('ImageGeneratorService: prompts stored', [
                'story_id' => $story->id,
                'character_count' => $charactersByName->count(),
                'environment_count' => $environmentsByName->count(),
                'prop_count' => $propsByName->count(),
                'prompt_count' => count($prompts),
            ]);
        } catch (\Throwable $e) {
            if ($user && $debitedAmount > 0) {
                $this->wallet->refund($user, $debitedAmount, 'image_prompt', $story, ['error' => $e->getMessage()]);
            }

            Log::error('ImageGeneratorService::generatePromptsForStory failed', [
                'story_id' => $story->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => Str::limit($e->getTraceAsString(), 3000),
            ]);

            throw $e;
        }
    }

    /**
     * Shared reuse-or-create logic for characters/environments/props: the
     * agent's response for each type is a flat array of
     * {name, image_prompt}. LOCKED ones (already known, already had an
     * image_prompt) get left alone; new/NEED_DESIGN ones get created or
     * have their image_prompt filled in for the first time.
     *
     * @param class-string $modelClass
     * @return \Illuminate\Support\Collection<string, \Illuminate\Database\Eloquent\Model> keyed by lowercased name
     */
    protected function reconcileAssets(string $modelClass, array $entries, Story $story, Collection $existing): \Illuminate\Support\Collection
    {
        $byName = $existing->keyBy(fn ($a) => Str::lower($a->name));

        foreach ($entries as $entry) {
            $name = $entry['name'] ?? null;
            if (!$name) {
                continue;
            }

            $key = Str::lower($name);
            $asset = $byName->get($key);

            if ($asset) {
                if (empty($asset->img_url) && empty($asset->image_prompt)) {
                    $asset->update(['image_prompt' => $entry['image_prompt'] ?? null]);
                }
            } else {
                $asset = $modelClass::create([
                    'story_id' => $story->id,
                    'series_id' => $story->series_id,
                    'user_id' => $story->user_id,
                    'name' => $name,
                    'image_prompt' => $entry['image_prompt'] ?? null,
                ]);
            }

            $byName->put($key, $asset);
        }

        return $byName;
    }

    /**
     * Resolves a scene entry's *_names array (e.g. character_names) into
     * asset ids via the name=>model map reconcileAssets() built, logging
     * and skipping (rather than silently stranding) any name the model
     * referenced in a scene but never declared up front.
     */
    protected function resolveIds(array $names, \Illuminate\Support\Collection $byName, Story $story, string $assetType): array
    {
        return collect($names)
            ->map(function (string $name) use ($byName, $story, $assetType) {
                $key = Str::lower($name);

                if ($byName->has($key)) {
                    return $byName[$key]->id;
                }

                Log::warning("ImageGeneratorService: scene referenced undeclared {$assetType}, skipping", [
                    'story_id' => $story->id,
                    'name' => $name,
                ]);

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    public function generateCharacterImage(Character $character): bool
    {
        return $this->generateReferencePortrait($character, 'character_portrait', 'character-images');
    }

    public function generateEnvironmentImage(Environment $environment): bool
    {
        return $this->generateReferencePortrait($environment, 'environment_reference', 'environment-images');
    }

    public function generatePropImage(Prop $prop): bool
    {
        return $this->generateReferencePortrait($prop, 'prop_reference', 'prop-images');
    }

    /**
     * Shared by generateCharacterImage/generateEnvironmentImage/generatePropImage
     * — all three assets have an identical generation lifecycle (see
     * App\Models\Concerns\HasReferenceImage), the only differences being
     * the coin cost config key and the storage path prefix.
     *
     * @param Character|Environment|Prop $asset
     */
    protected function generateReferencePortrait($asset, string $costConfigKey, string $storagePrefix): bool
    {
        // Character keeps its own user_id (backfilled by migration); fall
        // back to story/series lookup for any pre-migration edge case.
        // Environment/Prop always have user_id set directly at creation.
        $user = $asset->user ?? $asset->story?->user ?? $asset->series?->user;
        $cost = (int) config("coins.costs.{$costConfigKey}");

        $assetType = class_basename($asset);

        if ($user) {
            if (!$this->limits->canGenerateImage($user)) {
                Log::info("ImageGeneratorService: daily/monthly image limit reached, skipping {$assetType}", ['id' => $asset->id, 'user_id' => $user->id]);
                throw new UserGenerationLimitReached($user->id, 'daily/monthly image limit reached');
            }
            if (!$this->wallet->canAfford($user, $cost)) {
                Log::info("ImageGeneratorService: insufficient coins, skipping {$assetType}", ['id' => $asset->id, 'user_id' => $user->id]);
                throw new UserGenerationLimitReached($user->id, 'insufficient coins');
            }
            $this->wallet->debit($user, $cost, $costConfigKey, $asset);
        }

        Log::info("ImageGeneratorService: generating {$assetType} image", [
            'id' => $asset->id,
            'story_id' => $asset->story_id,
            'name' => $asset->name,
        ]);

        $maxRetries = 3;
        $attempt = 0;
        $e = null;

        while ($attempt < $maxRetries) {
            try {
                $image = $this->imageProvider->generatePortrait($asset->image_prompt);

                $basePath = "{$storagePrefix}/{$asset->story_id}/{$asset->id}-" . Str::random(8);

                $optimizedPath = $image->storeOptimizedAs("{$basePath}.webp");
                $qualityPath = $image->storeQualityAs("{$basePath}.jpg");

                $url = Storage::disk('public')->url($optimizedPath);
                $qualityUrl = Storage::disk('public')->url($qualityPath);

                $asset->update([
                    'img_url' => $url,
                    'img_url_quality' => $qualityUrl,
                    'generated_at' => now(),
                    'last_generation_error' => null,
                ]);

                return true;
            } catch (RateLimitedException $ex) {
                $e = $ex;
                $attempt++;
                if ($attempt >= $maxRetries) break;

                // Exponential backoff: sleep 10, 20, then 40 seconds
                sleep(pow(2, $attempt) * 5);
                Log::warning("Rate limited on {$assetType} {$asset->id}, retrying... Attempt {$attempt}");
            } catch (\Throwable $ex) {
                $e = $ex;
                break; // Exit loop for non-rate-limit errors
            }
        }

        if ($user) {
            $this->wallet->refund($user, $cost, $costConfigKey, $asset, ['error' => $e?->getMessage()]);
        }
        $this->handleFailure($asset, $e);
        return false;
    }

    public function generateImage(StoryImagePrompt $imagePrompt): bool
    {
        $user = $imagePrompt->user ?? $imagePrompt->story?->user;
        $cost = (int) config('coins.costs.image_generation');

        if ($user) {
            if (!$this->limits->canGenerateImage($user)) {
                Log::info('ImageGeneratorService: daily/monthly image limit reached, skipping scene', ['story_image_prompt_id' => $imagePrompt->id, 'user_id' => $user->id]);
                throw new UserGenerationLimitReached($user->id, 'daily/monthly image limit reached');
            }
            if (!$this->wallet->canAfford($user, $cost)) {
                Log::info('ImageGeneratorService: insufficient coins, skipping scene', ['story_image_prompt_id' => $imagePrompt->id, 'user_id' => $user->id]);
                throw new UserGenerationLimitReached($user->id, 'insufficient coins');
            }
            $this->wallet->debit($user, $cost, 'image_generation', $imagePrompt);
        }

        Log::info('ImageGeneratorService: generating scene image', [
            'story_image_prompt_id' => $imagePrompt->id,
            'story_id' => $imagePrompt->story_id,
        ]);

        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                [$prompt, $referenceImageUrls] = $this->buildScenePromptAndReferences($imagePrompt);

                $image = $this->imageProvider->generateScene($prompt, $referenceImageUrls);

                $basePath = "story-images/{$imagePrompt->story_id}/{$imagePrompt->id}-" . Str::random(8);
 
                $optimizedPath = $image->storeOptimizedAs("{$basePath}.webp");
                $qualityPath = $image->storeQualityAs("{$basePath}.jpg");
                $url = Storage::disk('public')->url($optimizedPath);
                $qualityUrl = Storage::disk('public')->url($qualityPath);

                if (!empty($imagePrompt->caption)) {
                    $this->captionOverlay->apply(
                        Storage::disk('public')->path($optimizedPath),
                        $imagePrompt->caption
                    );
                    $this->captionOverlay->apply(
                        Storage::disk('public')->path($qualityPath),
                        $imagePrompt->caption
                    );
                }
                $imagePrompt->update([
                    'image_generated_url' => $url,
                    'image_generated_url_quality' => $qualityUrl,
                    'image_coin_cost' => $cost,
                    'generated_at' => now(),
                    'last_generation_error' => null,
                ]);

                return true;

            } catch (RateLimitedException $e) {
                $attempt++;
                if ($attempt >= $maxRetries) break;
                
                sleep(pow(2, $attempt) * 5);
                Log::warning("Rate limited on prompt {$imagePrompt->id}, retrying... Attempt {$attempt}");
            } catch (\Throwable $e) {
                Log::error("Unrecoverable AI Error: " . $e->getMessage());
                break;
            }
        }

        if ($user) {
            $this->wallet->refund($user, $cost, 'image_generation', $imagePrompt, ['error' => ($e ?? null)?->getMessage()]);
        }
        $this->handleFailure($imagePrompt, $e ?? null);
        return false;
    }

    /**
     * The heart of this class's answer to "what if the image model can't
     * take reference images": ask the active provider up front (once,
     * cheaply — no extra API call) whether it can actually use reference
     * pixels at all.
     *
     * - If yes (e.g. Gemini): pass every known character/environment/prop's
     *   img_url straight through as reference images, prompt text
     *   untouched — this is strictly better than describing them in words.
     * - If no (e.g. Cloudflare Workers AI SDXL, or Together on a non-Kontext
     *   model): don't pass any URLs the provider would just silently drop —
     *   instead fold each asset's locked-in image_prompt description
     *   straight into the scene prompt text, so a text-only model still has
     *   *something* concrete to stay consistent with instead of inventing
     *   the character/location fresh every single time.
     *
     * @return array{0: string, 1: string[]} [$prompt, $referenceImageUrls]
     */
    protected function buildScenePromptAndReferences(StoryImagePrompt $imagePrompt): array
    {
        $assets = $imagePrompt->allReferenceAssets();

        if ($this->imageProvider->supportsReferenceImages()) {
            $referenceImageUrls = $assets->pluck('img_url')->filter()->values()->all();

            return [$imagePrompt->prompt, $referenceImageUrls];
        }

        $definitions = $assets
            ->map(fn ($asset) => $asset->definitionText())
            ->filter()
            ->implode("\n");

        $prompt = $definitions === ''
            ? $imagePrompt->prompt
            : "{$imagePrompt->prompt}\n\nKeep these established visual details consistent:\n{$definitions}";

        return [$prompt, []];
    }

    protected function handleFailure($model, ?\Throwable $e): void
    {
        $message = $e ? $e->getMessage() : 'Unknown error';
        Log::error('ImageGeneratorService failed', ['error' => $message]);
        
        $model->update([
            'last_generation_error' => Str::limit($message, 2000),
            'generation_attempts' => $model->generation_attempts + 1,
        ]);
    }
}