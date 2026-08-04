<?php

namespace App\Services\SocialPlatforms\Support;

use InvalidArgumentException;

/**
 * One place holding the OAuth2 authorize/token URLs + required scopes for
 * every provider except Pinterest (which keeps its own dedicated,
 * already-working flow in PinterestService/SocialAccountController —
 * not worth the risk of folding it into this generic path).
 *
 * Adding a platform's OAuth connect flow later is just adding one entry
 * here (Open/Closed) — SocialOAuthController itself never changes.
 */
class OAuthProviderConfig
{
    /**
     * A provider only counts as usable once its client_id AND
     * client_secret env vars are both set — used to gate both the
     * connect() endpoint (so it never builds a broken authorize URL with
     * an empty client_id) and the frontend's "Coming soon" state.
     */
    public static function isConfigured(string $provider): bool
    {
        return filled(config("services.{$provider}.client_id")) && filled(config("services.{$provider}.client_secret"));
    }

    public static function for(string $provider): array
    {
        return match ($provider) {
            'linkedin' => [
                'authorize_url' => 'https://www.linkedin.com/oauth/v2/authorization',
                'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken',
                'scopes' => ['openid', 'profile', 'w_member_social'],
                'pkce' => false,
                'client_id' => config('services.linkedin.client_id'),
                'client_secret' => config('services.linkedin.client_secret'),
                'redirect_uri' => config('services.linkedin.redirect_uri'),
            ],
            'twitter' => [
                'authorize_url' => 'https://twitter.com/i/oauth2/authorize',
                'token_url' => 'https://api.twitter.com/2/oauth2/token',
                // Note: this covers posting tweets via v2 with the resulting
                // OAuth2 user token. Media upload is a *separate* OAuth 1.0a
                // credential set (services.twitter.consumer_key etc, set up
                // manually in the Twitter dev portal) — see TwitterPlatform.
                'scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access'],
                'pkce' => true,
                'client_id' => config('services.twitter.client_id'),
                'client_secret' => config('services.twitter.client_secret'),
                'redirect_uri' => config('services.twitter.redirect_uri'),
            ],
            'youtube' => [
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'scopes' => ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly'],
                'pkce' => false,
                // access_type=offline + prompt=consent are required to
                // actually get a refresh_token back from Google.
                'extra_authorize_params' => ['access_type' => 'offline', 'prompt' => 'consent'],
                'client_id' => config('services.youtube.client_id'),
                'client_secret' => config('services.youtube.client_secret'),
                'redirect_uri' => config('services.youtube.redirect_uri'),
            ],
            'instagram' => [
                'authorize_url' => 'https://www.facebook.com/' . config('services.instagram.graph_api_version') . '/dialog/oauth',
                'token_url' => 'https://graph.facebook.com/' . config('services.instagram.graph_api_version') . '/oauth/access_token',
                'scopes' => ['instagram_basic', 'instagram_content_publish', 'pages_show_list'],
                'pkce' => false,
                'client_id' => config('services.instagram.client_id'),
                'client_secret' => config('services.instagram.client_secret'),
                'redirect_uri' => config('services.instagram.redirect_uri'),
            ],
            'facebook' => [
                'authorize_url' => 'https://www.facebook.com/' . config('services.facebook.graph_api_version') . '/dialog/oauth',
                'token_url' => 'https://graph.facebook.com/' . config('services.facebook.graph_api_version') . '/oauth/access_token',
                'scopes' => ['pages_read_engagement', 'pages_show_list'],
                'pkce' => false,
                'client_id' => config('services.facebook.client_id'),
                'client_secret' => config('services.facebook.client_secret'),
                'redirect_uri' => config('services.facebook.redirect_uri'),
            ],
            default => throw new InvalidArgumentException("No OAuth config registered for provider [{$provider}]."),
        };
    }
}
