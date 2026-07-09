<?php

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Comma-separated in .env, e.g. CORS_ALLOWED_ORIGINS=http://localhost:5173,https://app.yourdomain.com
    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:5174, https://craftprofessorui.amowogbaje.com'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // JWT is a header (Authorization: Bearer), not a cookie, so no credentials needed.
    'supports_credentials' => false,

];
