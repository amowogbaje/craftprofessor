<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Coin Costs
    |--------------------------------------------------------------------------
    |
    | Every generation action costs coins, charged BEFORE the underlying AI
    | provider call is made (and refunded automatically if the call fails).
    | This is what keeps user-triggered generation bounded by what they've
    | actually paid for, since the developer is the one footing the AI bill.
    |
    */
    'costs' => [
        'image_prompt' => (int) env('COIN_COST_IMAGE_PROMPT', 2),
        'image_generation' => (int) env('COIN_COST_IMAGE_GENERATION', 10),
        'video_prompt' => (int) env('COIN_COST_VIDEO_PROMPT', 3),
        'video_generation' => (int) env('COIN_COST_VIDEO_GENERATION', 40),
        'character_portrait' => (int) env('COIN_COST_CHARACTER_PORTRAIT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | New User Bonus
    |--------------------------------------------------------------------------
    */
    'signup_bonus' => (int) env('COIN_SIGNUP_BONUS', 50),

    /*
    |--------------------------------------------------------------------------
    | Purchasable Coin Packages (shown on the top-up screen)
    |--------------------------------------------------------------------------
    | Amounts are in the smallest unit of the configured currency (e.g. kobo
    | for NGN with Flutterwave). Adjust to match real AI provider cost.
    |
    */
    'packages' => [
        ['coins' => 100, 'amount' => 1000, 'currency' => 'NGN', 'label' => 'Starter'],
        ['coins' => 550, 'amount' => 5000, 'currency' => 'NGN', 'label' => 'Popular (+10% bonus)'],
        ['coins' => 1200, 'amount' => 10000, 'currency' => 'NGN', 'label' => 'Pro (+20% bonus)'],
    ],
];
