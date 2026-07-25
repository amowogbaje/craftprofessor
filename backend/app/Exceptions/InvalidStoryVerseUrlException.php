<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the input handed to StoryVerseImportService isn't a bare
 * slug and doesn't look like a StoryVerse story URL
 * (https://storyverse.amowogbaje.com/stories/{slug}) — as opposed to
 * StoryVerseStoryNotFoundException, which means the format was fine but
 * StoryVerse doesn't know that story.
 */
class InvalidStoryVerseUrlException extends RuntimeException
{
}
