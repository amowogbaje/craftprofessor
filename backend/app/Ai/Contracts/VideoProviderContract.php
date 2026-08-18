<?php

namespace App\Ai\Contracts;

interface VideoProviderContract
{
    /**
     * Turn a still image into a short video clip.
     *
     * @param string $motionPrompt Camera/motion direction text (see VideoPromptAgent).
     * @param string $sourceImageUrl Publicly reachable URL of the still frame to animate.
     * @return string Raw video bytes (mp4) ready to store as-is.
     */
    public function generate(string $motionPrompt, string $sourceImageUrl): string;

    /** Short identifier stored on Video::provider (e.g. "veo", "agnes"). */
    public function name(): string;
}
