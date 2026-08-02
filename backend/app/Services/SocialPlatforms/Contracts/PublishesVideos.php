<?php

namespace App\Services\SocialPlatforms\Contracts;

use App\Models\Video;
use App\Services\SocialPlatforms\DTO\SocialPostResult;

/**
 * Implemented by any platform that can post a video: Twitter/X, YouTube,
 * Instagram (Reels), Facebook.
 */
interface PublishesVideos extends SocialPlatform
{
    public function publishVideo(Video $video): SocialPostResult;
}
