<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cause creation fee
    |--------------------------------------------------------------------------
    |
    | Charged once, via Flutterwave, when a user creates a Cause. A dev can
    | bypass this for a specific user with:
    |   php artisan causes:waive-payment {user_id} [--cause=ID]
    |
    */
    'creation_fee' => (float) env('CAUSE_CREATION_FEE', 10.00),
    'creation_fee_currency' => env('CAUSE_CREATION_FEE_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast scheduling
    |--------------------------------------------------------------------------
    |
    | How far in the future a member can schedule a broadcast, and the
    | default timezone used if the scheduling user has none set on their
    | publish settings.
    |
    */
    'max_schedule_days_ahead' => (int) env('CAUSE_MAX_SCHEDULE_DAYS_AHEAD', 90),
    'default_timezone' => env('CAUSE_DEFAULT_TIMEZONE', 'UTC'),

];
