<?php

namespace App\Services\SocialPlatforms\Contracts;

use App\Models\StoryImagePrompt;
use Illuminate\Support\Collection;

/**
 * Pinterest-specific capability: a single image can go out to more than
 * one board. Kept as its own interface (rather than folded into
 * PublishesImages) since "which boards" is a concept unique to
 * board-based platforms — nothing else needs this method.
 */
interface SupportsMultipleBoards
{
    /**
     * @return Collection<int, \App\Models\PinterestBoard> 1-3 boards to post to.
     */
    public function resolveBoardsForImage(StoryImagePrompt $imagePrompt): Collection;
}
