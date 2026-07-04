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
    public function __construct(protected ImageGeneratorAgent $imageAgent)
    {
    }

    public function generatePromptsForStory(Story $story): void
    {
        Log::info('ImageGeneratorService: generating prompts for story', ['story_id' => $story->id]);

        try {
            $story->imagePrompts()->delete();

            $response = (new ImagePromptAgent($story))
                ->prompt('Generate the character and scene prompts now.');

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
                    'story_id' => $story->id,
                    'prompt' => $entry['prompt'],
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
        $this->handleFailure($character, $e ?? null);
        return false;
    }

    public function generateImage(StoryImagePrompt $imagePrompt): bool
    {
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
                break;
            }
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