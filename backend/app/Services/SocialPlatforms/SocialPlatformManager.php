<?php

namespace App\Services\SocialPlatforms;

use App\Models\SocialAccount;
use InvalidArgumentException;

/**
 * Dependency Inversion in practice: PostPinterestPins, a future
 * PostToLinkedIn, PostVideoToYouTube etc. all depend on this + the
 * capability interfaces (PublishesImages, PublishesVideos, PublishesText) —
 * never on a concrete PinterestPlatform/LinkedInPlatform/etc directly.
 * Adding a new platform later (Open/Closed) means adding one line to
 * $platforms + the class itself; nothing that already depends on this
 * manager needs to change.
 */
class SocialPlatformManager
{
    /** @var array<string, class-string<AbstractSocialPlatform>> */
    protected array $platforms = [
        'pinterest' => PinterestPlatform::class,
        'linkedin' => LinkedInPlatform::class,
        'twitter' => TwitterPlatform::class,
        'youtube' => YouTubePlatform::class,
        'instagram' => InstagramPlatform::class,
        'facebook' => FacebookPlatform::class,
    ];

    public function forAccount(SocialAccount $account): AbstractSocialPlatform
    {
        $class = $this->platforms[$account->provider] ?? null;

        if (!$class) {
            throw new InvalidArgumentException("No platform implementation registered for provider [{$account->provider}].");
        }

        return $class::forAccount($account);
    }

    public function forUser(int $userId, string $provider): AbstractSocialPlatform
    {
        $account = SocialAccount::where('user_id', $userId)
            ->where('provider', $provider)
            ->firstOrFail();

        return $this->forAccount($account);
    }

    /** @param class-string $capability e.g. PublishesVideos::class */
    public function supports(string $provider, string $capability): bool
    {
        $class = $this->platforms[$provider] ?? null;

        return $class && is_subclass_of($class, $capability);
    }

    /** @return string[] every provider implementing the given capability, e.g. everything that PublishesVideos. */
    public function providersSupporting(string $capability): array
    {
        return array_keys(array_filter(
            $this->platforms,
            fn (string $class) => is_subclass_of($class, $capability)
        ));
    }
}
