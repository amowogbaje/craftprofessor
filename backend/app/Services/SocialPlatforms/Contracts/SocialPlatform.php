<?php

namespace App\Services\SocialPlatforms\Contracts;

/**
 * The only thing every platform is guaranteed to have. Actual publishing
 * ability comes from the capability interfaces below (PublishesImages,
 * PublishesVideos, PublishesText) — a platform implements whichever of
 * those actually apply to it (Interface Segregation: YouTubePlatform has
 * no business being forced to implement publishImage()).
 */
interface SocialPlatform
{
    /** The provider string stored on social_accounts.provider, e.g. "pinterest". */
    public function name(): string;
}
