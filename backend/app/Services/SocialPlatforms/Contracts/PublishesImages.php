<?php

namespace App\Services\SocialPlatforms\Contracts;

use App\Models\StoryImagePrompt;
use App\Services\SocialPlatforms\DTO\SocialPostResult;

/**
 * Implemented by any platform that can post a standalone image:
 * Pinterest, LinkedIn, Twitter/X, Instagram, Facebook.
 */
interface PublishesImages extends SocialPlatform
{
    public function publishImage(StoryImagePrompt $imagePrompt): SocialPostResult;
}
