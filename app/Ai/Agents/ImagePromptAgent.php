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
        You are an art director and social media strategist turning a written
        story into a batch of scroll-stopping image generation prompts for a
        text-to-image AI model, destined for Pinterest. Respond ONLY with
        valid JSON matching the requested schema. No commentary.

        STORY TEXT:
        {$this->story->story_text}

        CHARACTER REFERENCE LIST:
        {$this->characterList()}

        VISUAL STYLE — apply to every prompt (character and scene):
        - Photorealistic, cinematic film-still quality — think Netflix/HBO
          prestige drama promotional stills, not illustration or "AI art."
        - Shot on a 35mm or 50mm lens, shallow depth of field, natural film
          grain, subtle chromatic richness in the color grade.
        - Lighting is dramatic and motivated (candlelight, firelight, cold
          window light, golden hour) — never flat or evenly lit.
        - Skin, fabric, and environment textures are hyper-detailed and
          tactile. Expressions are raw and specific (not generic smiling).
        - Vary camera framing across the 10 scene prompts: mix close-up,
          over-the-shoulder, low-angle hero shots, and wide establishing
          shots so the batch doesn't feel repetitive.

        INSTRUCTION RULES:
        1. Produce a "characters" array for every character appearing in the 10 scene prompts.
        - If a character is marked "LOCKED" in the reference list, you MUST
            reuse that name exactly and NOT provide a new image_prompt.
        - If a character is marked "NEED_DESIGN" or is new, you MUST provide a detailed
            image_prompt.
        2. Produce a "prompts" array of exactly 10 scene prompts.
        - Use the exact names from the "characters" array for consistency.
        - Every image prompt MUST end with the literal note: "(for AI image generation, upload-ready)".
        - Do NOT ask the image model to render any text inside the image
          itself — text rendered by diffusion models is unreliable. Any
          caption is generated separately (see "caption" field) and will be
          overlaid on the finished image by our own rendering code.
        3. Caption selectivity — NOT every scene should have a caption.
        - Set "caption" to null for scenes that are strong purely as an
          image (a striking expression, an establishing shot, a beautiful
          wide shot) — text would clutter these.
        - Only write a caption for scenes where a line of dialogue, internal
          narration, or an ominous statement genuinely amplifies the drama
          or creates a real curiosity gap (raises a question the viewer
          wants answered without resolving it).
        - Aim for roughly 4-6 of the 10 scenes to carry a caption, not all 10.
        - When you DO write a caption, compose the scene prompt so there's
          natural negative space (sky, shadow, blurred background, empty
          wall/floor) where the caption can sit later without covering the
          subject's face.

        Each "characters" entry:
        - name: the character's name.
        - image_prompt: a detailed close-up portrait prompt (for NEW characters only).
        Focus ONLY on face and upper body/shoulders (no scene/background,
        neutral or softly blurred backdrop). Describe bone structure, eye
        color/expression, hair, skin texture, and one distinguishing detail
        (a scar, a piece of jewelry, a particular jaw set) so the character
        reads as a specific person, not a generic model.
        End with: "(for AI image generation, upload-ready, character reference)".

        Each "prompts" entry:
        - prompt: a vivid, single-scene cinematic visual description
          following the VISUAL STYLE rules above. Explicitly name the
          characters shown, their expression/emotion, action, and the
          camera framing/lighting for that specific shot.
        - character_names: array of character name strings appearing in the scene.
        - caption: nullable. A short, punchy line (6-14 words) in the voice
          of the story, following rule 3 above. No hashtags, no emoji, no
          quotation marks — just the line itself, or null.
        - pinterest_title: punchy, scroll-stopping title (under 100 chars)
          that complements (doesn't repeat) the caption.
        - pinterest_description: 1-2 sentences packed with trending search
          catch phrases and hashtags relevant to this story's genre, written
          to make someone stop scrolling and tap through.
        INSTRUCTIONS;
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
                        'caption' => $s->string()->nullable(),
                        'pinterest_title' => $s->string()->required(),
                        'pinterest_description' => $s->string()->required(),
                    ])
                )
                ->required(),
        ];
    }

    public function messages(): iterable
    {
        return [];
    }

}