<?php

namespace App\Ai\Agents;

use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Stringable;
use Laravel\Ai\Contracts\Agent;
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

    public function __construct(protected Story $story)
    {
    }

    public function instructions(): Stringable|string
    {
        return <<<INSTRUCTIONS
        You are an art director turning a written story into a batch of image
        generation prompts for a text-to-image AI model, destined for Pinterest.
        Respond only with the requested structured data — no commentary.

        STORY TEXT:
        {$this->story->story_text}

        KNOWN CHARACTERS: {$this->characterList()}

        Produce a "characters" array (one entry per distinct character who
        appears in your prompts — reuse KNOWN CHARACTERS where they fit; for
        any marked "design already locked", reuse that exact name and do not
        change their described appearance; invent consistent new names/designs
        only for characters not already on the list) and a "prompts" array of
        exactly 10 scene prompts.

        Each "characters" entry:
        - name: the character's name, used consistently across "prompts".
        - image_prompt: a detailed close-up portrait/reference-sheet prompt for
          an AI image generator, focused ONLY on that character's face and
          appearance (no scene/background action), written so the same
          character can be recognizably regenerated later. End it with the
          literal note "(for AI image generation, upload-ready, character
          reference)".

        Each "prompts" entry:
        - prompt: a vivid, single-scene visual description for an AI image
          generator. It MUST explicitly name the character(s) shown, reusing
          the exact names from "characters" above for consistency. End the
          prompt text with the literal note "(for AI image generation,
          upload-ready)".
        - character_names: array of character name strings depicted in that
          prompt (must match names in "characters").
        - pinterest_title: a punchy, scroll-stopping Pinterest pin title (under
          100 chars) using current trending Pinterest phrasing for this genre.
        - pinterest_description: a 1-2 sentence Pinterest description packed
          with trending, high-search catch phrases/hashtags relevant to this
          story's genre and hook, written to maximize saves/clicks.
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
                        'image_prompt' => $s->string()->required(),
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

    protected function characterList(): string
    {
        $known = $this->story->knownCharacters()->get();

        if ($known->isEmpty()) {
            return '(no named characters on file — invent fitting names consistent with the story)';
        }

        return $known->map(function ($c) {
            $status = $c->img_url
                ? 'design already locked, DO NOT redesign — reuse name exactly'
                : 'no design yet';

            return "{$c->name} ({$status})";
        })->implode(', ');
    }
}