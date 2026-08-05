<?php

namespace App\Services\SocialPlatforms\Contracts;

use App\Services\SocialPlatforms\DTO\SocialPostResult;

/**
 * Like PublishesVideos, but decoupled from the Video model — takes a raw
 * URL + caption instead. Implemented by every platform (Pinterest and
 * LinkedIn included) so any broadcast source — Cause media today — can
 * always send video, regardless of platform.
 */
interface PublishesRawVideo extends SocialPlatform
{
    public function publishRawVideo(string $videoUrl, string $caption, ?string $linkUrl = null): SocialPostResult;
}
