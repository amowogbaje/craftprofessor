<?php

namespace App\Ai\Agents;

use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Stringable;
use Laravel\Ai\Contracts\Agent;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a story's text + known characters and asks the model for a
 * structured batch of character portrait prompts, 10 scene image prompts,
 * and Pinterest-ready metadata for each scene.
 *
 * Story context is bound via the constructor (like a one-shot report, not a
 * conversation), so instructions() carries the full brief and prompt() is
 * just the trigger.
 */
class ImagePromptAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(protected Story $story, protected Collection $existingCharacters)
    {
    }

    protected function characterList(): string
    {
        return $this->existingCharacters->map(function ($c) {
            $hasPrompt = !empty($c->image_prompt);
            $status = $hasPrompt ? 'LOCKED' : 'NEED_DESIGN';
            $promptContext = $hasPrompt ? "Prompt: {$c->image_prompt}" : "";
            
            return "Name: {$c->name} ({$status}) {$promptContext}";
        })->implode("\n");
    }

    public function instructions(): Stringable|string
    {
        return <<<INSTRUCTIONS
        You are an art director turning a written story into a batch of image 
        generation prompts for a text-to-image AI model, destined for Pinterest.
        Respond ONLY with valid JSON matching the requested schema. No commentary.

        STORY TEXT:
        {$this->story->story_text}

        CHARACTER REFERENCE LIST:
        {$this->characterList()}

        INSTRUCTION RULES:
        1. Produce a "characters" array for every character appearing in the 10 scene prompts.
        - If a character is marked "LOCKED" in the reference list, you MUST 
            reuse that name exactly and NOT provide a new image_prompt.
        - If a character is marked "NEED_DESIGN" or is new, you MUST provide a detailed 
            image_prompt.
        2. Produce a "prompts" array of exactly 10 scene prompts.
        - Use the exact names from the "characters" array for consistency.
        - Every prompt MUST end with the literal note: "(for AI image generation, upload-ready)".

        Each "characters" entry:
        - name: the character's name.
        - image_prompt: a detailed close-up portrait prompt (for NEW characters only). 
        Focus ONLY on face and appearance (no scene/background). 
        End with: "(for AI image generation, upload-ready, character reference)".

        Each "prompts" entry:
        - prompt: vivid, single-scene visual description. Explicitly name the characters shown.
        - character_names: array of character name strings.
        - pinterest_title: punchy, scroll-stopping title (under 100 chars).
        - pinterest_description: 1-2 sentence description packed with trending search 
        catch phrases and hashtags relevant to this story's genre.
        INSTRUCTIONS;
    }

    public function messages(): iterable
    {
        return [];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'characters' => $schema->array()
                ->items(
                    $schema->object(fn (JsonSchema $s) => [
                        'name' => $s->string()->required(),
                        'image_prompt' => $s->string()->nullable(),
                    ])
                )
                ->required(),

            'prompts' => $schema->array()
                ->min(10)->max(10)
                ->items(
                    $schema->object(fn (JsonSchema $s) => [
                        'prompt' => $s->string()->required(),
                        'character_names' => $s->array()->items($s->string())->required(),
                        'pinterest_title' => $s->string()->required(),
                        'pinterest_description' => $s->string()->required(),
                    ])
                )
                ->required(),
        ];
    }

    
}