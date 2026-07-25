<?php

return [

    // Symmetric signing secret — generate with `php artisan jwt:secret`
    // (see the artisan command shipped alongside this) or any 32+ char random string.
    'secret' => env('JWT_SECRET'),

    'algo' => env('JWT_ALGO', 'HS256'),

    // Minutes a normal access token is valid for.
    'ttl' => (int) env('JWT_TTL', 60 * 24 * 7), // 7 days

    // Minutes a password-reset-purpose token is valid for — deliberately short.
    'reset_ttl' => (int) env('JWT_RESET_TTL', 10),

];
