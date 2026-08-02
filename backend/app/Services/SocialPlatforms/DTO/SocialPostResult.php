<?php

namespace App\Services\SocialPlatforms\DTO;

/**
 * The one return shape every platform's publish*() method produces,
 * regardless of what "posting" means on that platform (a pin, a tweet, a
 * video upload...). Callers (PostPinterestPins, future PostToLinkedIn,
 * etc.) only ever need to look at this, never at a platform-specific
 * response shape.
 */
final class SocialPostResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $externalPostId,
        public readonly ?string $error,
        public readonly ?int $pinterestBoardId = null,
    ) {
    }

    public static function success(string $externalPostId, ?int $pinterestBoardId = null): self
    {
        return new self(true, $externalPostId, null, $pinterestBoardId);
    }

    public static function failure(string $error, ?int $pinterestBoardId = null): self
    {
        return new self(false, null, $error, $pinterestBoardId);
    }
}
