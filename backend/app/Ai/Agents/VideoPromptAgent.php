<?php

namespace App\Ai\Agents;

use App\Models\StoryImagePrompt;
use Illuminate\Support\Stringable;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Turns a still scene image (and the prompt that generated it) into a
 * short motion/camera-direction prompt for an image-to-video model (Veo).
 * Bound to one StoryImagePrompt per instance, same pattern as ImagePromptAgent.
 */
class VideoPromptAgent implements Agent
{
    use Promptable;

    public function __construct(protected StoryImagePrompt $imagePrompt)
    {
    }

    public function instructions(): Stringable|string
    {
        return <<<INSTRUCTIONS
        You are a motion director. You are given the prompt that generated a
        still image, and you must write a short (2-4 sentence) prompt for an
        image-to-video AI model that brings that exact still to life.

        ORIGINAL IMAGE PROMPT:
        {$this->imagePrompt->prompt}

        Describe ONLY motion, camera behavior, and pacing — do not redescribe
        the subject's appearance (the model already has the source image).
        Favor subtle, natural motion: gentle camera drift, blinking, breeze,
        light movement — nothing chaotic or physically implausible.
        Keep the whole thing under 60 words. Respond with the prompt text only.
        INSTRUCTIONS;
    }

    public function messages(): iterable
    {
        return [];
    }
}
