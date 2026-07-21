<?php

return [
    'daily_image_cap' => env('IMAGE_DAILY_CAP', 30),
    'daily_cost_cap_cents' => env('IMAGE_DAILY_COST_CAP_CENTS', 5000), // $50/day
    'cost_per_portrait_cents' => env('IMAGE_COST_PORTRAIT_CENTS', 4),
    'cost_per_scene_cents' => env('IMAGE_COST_SCENE_CENTS', 8),
    'batch_size_per_run' => env('IMAGE_BATCH_SIZE', 10),
    'max_concurrent_jobs' => env('IMAGE_MAX_CONCURRENT', 5),

    // A character/prompt that has hard-failed this many times drops out of
    // the generation queue (oldest-first would otherwise keep re-selecting
    // it every run forever, starving everything queued behind it).
    'max_generation_attempts' => env('IMAGE_MAX_GENERATION_ATTEMPTS', 5),
];