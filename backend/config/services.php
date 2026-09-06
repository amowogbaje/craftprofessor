<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    'pinterest' => [
        'client_id' => env('PINTEREST_CLIENT_ID'),
        'client_secret' => env('PINTEREST_CLIENT_SECRET'),
        'redirect_uri' => env('PINTEREST_REDIRECT_URI'),
        'access_token' => env('PINTEREST_ACCESS_TOKEN'),
        'board_id' => env('PINTEREST_BOARD_ID'),
        'environment' => env('PINTEREST_ENVIRONMENT', 'production'), // "production" or "sandbox"
        'default_board_name' => env('PINTEREST_DEFAULT_BOARD_NAME', 'StoryVerse'),
        'default_board_description' => env('PINTEREST_DEFAULT_BOARD_DESCRIPTION'),

        // Hard cap on Pins posted per user per day, enforced by
        // App\Console\Commands\PostPinterestPins itself (not just by how
        // often it's scheduled) — see App\Models\StoryImagePrompt::scopeAwaitingPinterestPost().
        'max_pins_per_user_per_day' => env('PINTEREST_MAX_PINS_PER_USER_PER_DAY', 5),
        // Separate, smaller cap for full story-video posts (see
        // App\Console\Commands\PostPinterestStoryVideos) — a story video
        // is a one-off flagship post per finished story, not recurring
        // per-scene content, so it doesn't share the cap above.
        'max_story_videos_per_user_per_day' => env('PINTEREST_MAX_STORY_VIDEOS_PER_USER_PER_DAY', 3),
        // Timezone the daily cap resets in. Should match the timezone
        // Scheduler 3 runs in (routes/console.php) so "today" means the
        // same thing in both places.
        'daily_cap_timezone' => env('PINTEREST_DAILY_CAP_TIMEZONE', 'UTC'),
    ],

    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect_uri' => env('LINKEDIN_REDIRECT_URI'),
        // LinkedIn versions its REST API by calendar month (e.g. "202601").
        // Required on every call as the LinkedIn-Version header. Bump this
        // periodically — LinkedIn deprecates old versions after ~1 year.
        'api_version' => env('LINKEDIN_API_VERSION', '202601'),
    ],

    'twitter' => [
        // OAuth 2.0 (PKCE) app credentials — used for posting via API v2.
        'client_id' => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect_uri' => env('TWITTER_REDIRECT_URI'),
        // Media upload (both image and video/chunked) still lives on the
        // legacy v1.1 endpoint as of this writing and requires OAuth 1.0a
        // user-context signing — separate credentials from the OAuth2 app
        // above. See TwitterPlatform for why both exist.
        'consumer_key' => env('TWITTER_CONSUMER_KEY'),
        'consumer_secret' => env('TWITTER_CONSUMER_SECRET'),
        'access_token' => env('TWITTER_ACCESS_TOKEN'),
        'access_token_secret' => env('TWITTER_ACCESS_TOKEN_SECRET'),
    ],

    'youtube' => [
        // Standard Google OAuth2 app (same credential shape as "Sign in
        // with Google"), scoped to https://www.googleapis.com/auth/youtube.upload
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect_uri' => env('YOUTUBE_REDIRECT_URI'),
    ],

    'instagram' => [
        // Instagram Graph API — requires a Business/Creator account linked
        // to a Facebook Page, and the same Meta app as Facebook below.
        'client_id' => env('INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
        'redirect_uri' => env('INSTAGRAM_REDIRECT_URI'),
        'graph_api_version' => env('META_GRAPH_API_VERSION', 'v21.0'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
        'graph_api_version' => env('META_GRAPH_API_VERSION', 'v21.0'),
    ],

    'social_boards' => [
        // See PinterestBoardSelectionService::MAX_BOARDS_PER_PIN — kept
        // here too so it's visible alongside the other per-platform config
        // even though the constant is what's actually enforced in code.
        'max_boards_per_pin' => env('SOCIAL_MAX_BOARDS_PER_PIN', 3),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'flutterwave' => [
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'secret_hash' => env('FLUTTERWAVE_SECRET_HASH'), // set as "verif-hash" in the Flutterwave dashboard webhook config
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
    ],

    'storyverse' => [
        // The API host — CraftProfessor actually fetches
        // /api/stories/{slug}/json from HERE. NOT what people paste.
        // See App\Services\StoryVerseImportService::fetch().
        'base_url' => env('STORYVERSE_BASE_URL', 'https://storyverseapi.amowogbaje.com'),

        // The reader-facing host — the story URLs people actually copy
        // and paste look like https://{reader_base_url}/stories/{slug}.
        // CraftProfessor only checks pasted links look right against
        // THIS host; it never sends a request here.
        // See App\Services\StoryVerseImportService::extractSlug().
        'reader_base_url' => env('STORYVERSE_READER_BASE_URL', 'https://storyverse.amowogbaje.com'),
    ],

    'link_tracking' => [
        // Base URL the redirect route lives on. Defaults to this app's own
        // APP_URL since /r is defined in this app's routes/web.php.
        'base_url' => env('LINK_TRACKING_BASE_URL', env('APP_URL', 'http://localhost')),
    ],

    'termii' => [
        // SMS OTP provider — swap for Twilio/other if preferred, see OtpService.
        'key' => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID', 'AppOTP'),
        'base_url' => env('TERMII_BASE_URL', 'https://api.ng.termii.com/api'),
    ],

];
