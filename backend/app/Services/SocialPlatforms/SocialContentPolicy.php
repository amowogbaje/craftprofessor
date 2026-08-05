<?php

namespace App\Services\SocialPlatforms;

/**
 * Central place for "what shape of content should this platform get"
 * decisions that apply across every broadcast source (Cause media today).
 * Currently just one rule, but keeping it named/centralized means adding
 * the next rule doesn't mean hunting through platform classes for it.
 */
class SocialContentPolicy
{
    /**
     * LinkedIn and Facebook read better as a text post (title + details +
     * link) than as an image post with a caption — so image-type content
     * is sent to them as text instead of the image itself. Configurable
     * via SOCIAL_TEXT_PREFERRED_PLATFORMS (config/social.php).
     */
    public static function prefersTextOverImage(string $provider): bool
    {
        return in_array($provider, config('social.text_preferred_platforms', ['linkedin', 'facebook']), true);
    }
}
