<?php

namespace App\Ai\Contracts;

interface TtsProviderContract
{
    /**
     * Turn one line of text into spoken audio.
     *
     * @param string|null $voice Provider-specific voice name/id to use for
     *   just this call (e.g. a character's assigned dialogue voice — see
     *   Character::voice / NarrationAudioService) instead of the provider's
     *   configured default. Null uses that default, same as before this
     *   parameter existed.
     * @return string Raw audio bytes (mp3), ready to store as-is.
     */
    public function speak(string $text, ?string $voice = null): string;

    /** File extension the returned bytes should be saved with (no dot) — e.g. "mp3", "wav". */
    public function extension(): string;

    /** Short identifier for logging/debugging which provider produced a clip. */
    public function name(): string;
}
