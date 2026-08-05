<?php

namespace App\Services\SocialPlatforms;

use App\Services\SocialPlatforms\Contracts\PublishesRawImage;
use App\Services\SocialPlatforms\Contracts\PublishesRawVideo;
use App\Services\SocialPlatforms\Contracts\PublishesText;
use App\Services\SocialPlatforms\DTO\BroadcastContent;
use App\Services\SocialPlatforms\DTO\SocialPostResult;

/**
 * The single place that decides *what shape* a piece of content takes on
 * a given platform, as opposed to AbstractSocialPlatform/the individual
 * platform classes, which only know *how* to publish a shape once it's
 * been decided.
 *
 * Policy, in order:
 *  1. Video content always goes out as video — every platform implements
 *     PublishesRawVideo.
 *  2. Image content goes out as text on platforms configured as
 *     "text preferred" (LinkedIn, Facebook by default — see
 *     SocialContentPolicy / config/social.php).
 *  3. Everywhere else, image content goes out as an actual image post.
 *  4. Falls back to text if a platform has no image capability at all.
 */
class SocialContentRouter
{
    public function publish(AbstractSocialPlatform $platform, BroadcastContent $content): SocialPostResult
    {
        if ($content->type === 'video') {
            if (!$platform instanceof PublishesRawVideo) {
                return SocialPostResult::failure("{$platform->name()} does not support video publishing.");
            }

            return $platform->publishRawVideo((string) $content->mediaUrl, $content->text(), $content->linkUrl);
        }

        if (SocialContentPolicy::prefersTextOverImage($platform->name()) && $platform instanceof PublishesText) {
            return $platform->publishText($content->text(), $content->linkUrl);
        }

        if ($platform instanceof PublishesRawImage) {
            return $platform->publishRawImage((string) $content->mediaUrl, $content->title ?? '', $content->details, $content->linkUrl);
        }

        if ($platform instanceof PublishesText) {
            return $platform->publishText($content->text(), $content->linkUrl);
        }

        return SocialPostResult::failure("{$platform->name()} does not support image or text publishing.");
    }
}
