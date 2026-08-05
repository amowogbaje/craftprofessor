<?php

namespace App\Services\SocialPlatforms\Contracts;

use App\Services\SocialPlatforms\DTO\SocialPostResult;

/**
 * Implemented by any platform that supports a plain text-only post
 * (no image/video attached): LinkedIn, Twitter/X, Facebook.
 */
interface PublishesText extends SocialPlatform
{
    public function publishText(string $text, ?string $linkUrl = null): SocialPostResult;
}


