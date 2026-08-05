<?php

namespace App\Services\SocialPlatforms\Contracts;

use App\Services\SocialPlatforms\DTO\SocialPostResult;

/**
 * Like PublishesImages, but decoupled from StoryImagePrompt — takes a raw
 * URL + text fields instead. Used by anything publishing an image that
 * didn't originate from the Pinterest-automation pipeline, e.g. Cause
 * media (see App\Services\Causes\CauseBroadcastService and
 * App\Services\SocialPlatforms\SocialContentRouter).
 */
interface PublishesRawImage extends SocialPlatform
{
    public function publishRawImage(string $imageUrl, string $title, ?string $details = null, ?string $linkUrl = null): SocialPostResult;
}
