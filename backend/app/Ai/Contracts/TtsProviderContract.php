<?php

namespace App\Ai\Contracts;

interface TtsProviderContract
{
    /**
     * Turn one scene's narration line into spoken audio.
     *
     * @return string Raw audio bytes (mp3), ready to store as-is.
     */
    public function speak(string $text): string;

    /** Short identifier for logging/debugging which provider produced a clip. */
    public function name(): string;
}
