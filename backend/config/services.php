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
        'default_board_name' => env('PINTEREST_DEFAULT_BOARD_NAME', 'Storyframe'),
        'default_board_description' => env('PINTEREST_DEFAULT_BOARD_DESCRIPTION'),

        // Hard cap on Pins posted per user per day, enforced by
        // App\Console\Commands\PostPinterestPins itself (not just by how
        // often it's scheduled) — see App\Models\StoryImagePrompt::scopeAwaitingPinterestPost().
        'max_pins_per_user_per_day' => env('PINTEREST_MAX_PINS_PER_USER_PER_DAY', 5),
        // Timezone the daily cap resets in. Should match the timezone
        // Scheduler 3 runs in (routes/console.php) so "today" means the
        // same thing in both places.
        'daily_cap_timezone' => env('PINTEREST_DAILY_CAP_TIMEZONE', 'UTC'),
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
