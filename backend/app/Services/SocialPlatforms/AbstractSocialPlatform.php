<?php

namespace App\Services\SocialPlatforms;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log as LogFacade;
use Psr\Log\LoggerInterface;

/**
 * Everything every platform needs regardless of what it publishes:
 * - bound to one connected SocialAccount (single responsibility: one
 *   instance == one account's credentials, not a static/global client)
 * - a per-platform log channel (config/logging.php has one per provider)
 * - a single place each platform records a SocialPost row, so
 *   PinterestPlatform, LinkedInPlatform etc. don't each reimplement it
 *
 * Token refresh is intentionally NOT handled here — it's provider-specific
 * enough (Pinterest's OAuth2 refresh vs. Twitter's OAuth1.0a vs. others)
 * that each platform's own connect/refresh flow owns it, the same way
 * PinterestService::ensureFreshToken() already does. What IS shared is
 * everything above.
 */
abstract class AbstractSocialPlatform
{
    public function __construct(protected SocialAccount $account)
    {
    }

    public static function forAccount(SocialAccount $account): static
    {
        return new static($account);
    }

    protected function log(): LoggerInterface
    {
        return LogFacade::channel($this->name());
    }

    protected function recordPost(array $attributes): \App\Models\SocialPost
    {
        return \App\Models\SocialPost::updateOrCreate(
            [
                'story_image_prompt_id' => $attributes['story_image_prompt_id'] ?? null,
                'video_id' => $attributes['video_id'] ?? null,
                'platform' => $this->name(),
                'pinterest_board_id' => $attributes['pinterest_board_id'] ?? null,
            ],
            [
                'user_id' => $this->account->user_id,
                'status' => $attributes['status'],
                'external_post_id' => $attributes['external_post_id'] ?? null,
                'error' => $attributes['error'] ?? null,
                'posted_at' => ($attributes['status'] ?? null) === 'posted' ? now() : null,
            ]
        );
    }
}
