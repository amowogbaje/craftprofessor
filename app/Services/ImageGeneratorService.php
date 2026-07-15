<?php

namespace App\Services;

use App\Ai\Agents\ImageGeneratorAgent;
use App\Ai\Agents\ImagePromptAgent;
use App\Models\Character;
use App\Models\Story;
use App\Models\StoryImagePrompt;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\RateLimitedException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageGeneratorService
{
    public function __construct(
        protected \App\Ai\Contracts\ImageProviderContract $imageAgent,
        protected \App\Services\WalletService $wallet,
        protected \App\Services\UsageLimitService $limits,
    ) {
    }

    public function generatePromptsForStory(Story $story): void
    {
        Log::info('ImageGeneratorService: generating prompts for story', ['story_id' => $story->id]);

        $existing = $story->knownCharacters()->get();

        $user = $story->user;
        $batchCost = (int) config('coins.costs.image_prompt') * 10; // schema always returns exactly 10 prompts

        if ($user) {
            if (!$this->wallet->canAfford($user, $batchCost)) {
                Log::info('ImageGeneratorService: skipping prompt generation, insufficient coins', [
                    'story_id' => $story->id, 'user_id' => $user->id, 'required' => $batchCost,
                ]);
                return;
            }

            $this->wallet->debit($user, $batchCost, 'image_prompt', $story);
        }

        try {
            $story->imagePrompts()->delete();

            $response = (new ImagePromptAgent($story, $existing))
                ->prompt('Generate only the missing character prompts and new scene prompts.');

            $characters = $response['characters'] ?? [];
            $prompts = $response['prompts'] ?? [];

            if (count($prompts) === 0) {
                throw new \RuntimeException('ImagePromptAgent returned no prompts.');
            }

            $charactersByName = $story->knownCharacters()->get()->keyBy(fn ($c) => Str::lower($c->name));

            $makeCharacter = function (string $name, ?string $imagePrompt = null) use ($story) {
                return Character::create([
                    'story_id' => $story->id,
                    'series_id' => $story->series_id,
                    'name' => $name,
                    'image_prompt' => $imagePrompt,
                ]);
            };

            foreach ($characters as $charEntry) {
                $name = $charEntry['name'] ?? null;
                if (!$name) {
                    continue;
                }

                $key = Str::lower($name);
                $character = $charactersByName->get($key);

                if ($character) {
                    if (empty($character->img_url) && empty($character->image_prompt)) {
                        $character->update(['image_prompt' => $charEntry['image_prompt'] ?? null]);
                    }
                } else {
                    $character = $makeCharacter($name, $charEntry['image_prompt'] ?? null);
                }

                $charactersByName->put($key, $character);
            }

            foreach ($prompts as $entry) {
                $names = collect($entry['character_names'] ?? []);

                $characterIds = $names
                    ->map(function (string $name) use ($charactersByName, $makeCharacter) {
                        $key = Str::lower($name);
                        if ($charactersByName->has($key)) {
                            return $charactersByName[$key]->id;
                        }
                        $character = $makeCharacter($name);
                        $charactersByName->put($key, $character);
                        return $character->id;
                    })
                    ->values()
                    ->all();

                StoryImagePrompt::create([
                    'user_id' => $story->user_id,
                    'story_id' => $story->id,
                    'prompt' => $entry['prompt'],
                    'prompt_coin_cost' => (int) config('coins.costs.image_prompt'),
                    'main_character_ids' => $characterIds,
                    'pinterest_title' => $entry['pinterest_title'] ?? null,
                    'pinterest_description' => $entry['pinterest_description'] ?? null,
                    'pinterest_link' => $story->medium_link,
                ]);
            }

            $story->update(['prompt_generated' => true]);

            Log::info('ImageGeneratorService: prompts stored', [
                'story_id' => $story->id,
                'character_count' => $charactersByName->count(),
                'prompt_count' => count($prompts),
            ]);
        } catch (\Throwable $e) {
            if ($user) {
                $this->wallet->refund($user, $batchCost, 'image_prompt', $story, ['error' => $e->getMessage()]);
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

    public function generateCharacterImage(Character $character): bool
    {
        $user = $character->story?->user ?? $character->series?->user;
        $cost = (int) config('coins.costs.character_portrait');

        if ($user) {
            if (!$this->limits->canGenerateImage($user)) {
                Log::info('ImageGeneratorService: daily/monthly image limit reached, skipping portrait', ['character_id' => $character->id, 'user_id' => $user->id]);
                return false;
            }
            if (!$this->wallet->canAfford($user, $cost)) {
                Log::info('ImageGeneratorService: insufficient coins, skipping portrait', ['character_id' => $character->id, 'user_id' => $user->id]);
                return false;
            }
            $this->wallet->debit($user, $cost, 'character_portrait', $character);
        }

        Log::info('ImageGeneratorService: generating character image', [
            'character_id' => $character->id,
            'story_id' => $character->story_id,
            'name' => $character->name,
        ]);

        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $image = $this->imageAgent->generatePortrait($character->image_prompt);

                $path = $image->storePubliclyAs(
                    "character-images/{$character->story_id}/{$character->id}-" . Str::random(8) . '.png'
                );
                $url = Storage::disk('public')->url($path);

                $character->update([
                    'img_url' => $url,
                    'generated_at' => now(),
                    'last_generation_error' => null,
                ]);

                return true;

            } catch (RateLimitedException $e) {
                $attempt++;
                if ($attempt >= $maxRetries) break;
                
                // Exponential backoff: sleep 10, 20, then 40 seconds
                sleep(pow(2, $attempt) * 5); 
                Log::warning("Rate limited on character {$character->id}, retrying... Attempt {$attempt}");
            } catch (\Throwable $e) {
                break; // Exit loop for non-rate-limit errors
            }
        }

        // Error handling if exhausted retries or non-rate-limit error occurred
        if ($user) {
            $this->wallet->refund($user, $cost, 'character_portrait', $character, ['error' => ($e ?? null)?->getMessage()]);
        }
        $this->handleFailure($character, $e ?? null);
        return false;
    }

    public function generateImage(StoryImagePrompt $imagePrompt): bool
    {
        $user = $imagePrompt->user ?? $imagePrompt->story?->user;
        $cost = (int) config('coins.costs.image_generation');

        if ($user) {
            if (!$this->limits->canGenerateImage($user)) {
                Log::info('ImageGeneratorService: daily/monthly image limit reached, skipping scene', ['story_image_prompt_id' => $imagePrompt->id, 'user_id' => $user->id]);
                return false;
            }
            if (!$this->wallet->canAfford($user, $cost)) {
                Log::info('ImageGeneratorService: insufficient coins, skipping scene', ['story_image_prompt_id' => $imagePrompt->id, 'user_id' => $user->id]);
                return false;
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
                $referenceImageUrls = $imagePrompt->mainCharacters()
                    ->pluck('img_url')
                    ->filter()
                    ->values()
                    ->all();

                $image = $this->imageAgent->generateScene($imagePrompt->prompt, $referenceImageUrls);

                $path = $image->storePubliclyAs(
                    "story-images/{$imagePrompt->story_id}/{$imagePrompt->id}-" . Str::random(8) . '.png'
                );
                $url = Storage::disk('public')->url($path);

                $imagePrompt->update([
                    'image_generated_url' => $url,
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