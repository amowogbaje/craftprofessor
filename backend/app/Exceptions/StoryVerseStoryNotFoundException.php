<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when StoryVerse's /api/stories/{slug}/json endpoint responds 404 —
 * the link/slug was well-formed but no such story exists on StoryVerse.
 */
class StoryVerseStoryNotFoundException extends RuntimeException
{
}
