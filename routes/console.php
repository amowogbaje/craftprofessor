<?php

use Illuminate\Support\Facades\Schedule;



// Scheduler 1: check for new image prompts every 30 minutes
Schedule::command('story:generate-image-prompts')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('images:cleanup-published')
    ->daily()
    ->withoutOverlapping();

// Scheduler 2: generate up to 3 images/day — every 15 min, active for a 4-hour window
Schedule::command('story:generate-images --limit=5 --time-budget=50')
    ->everyFifteenMinutes()
    // ->between('08:00', '12:00') // 4-hour window — adjust to taste
    ->withoutOverlapping(10)
    ->runInBackground();

// Scheduler 3: 2 Pinterest posts/day — every 5 min, active for a 5-hour window
Schedule::command('story:post-pinterest-pin')
    ->everyFiveMinutes()
    ->between('23:00', '23:59')
    ->timezone('UTC')
    ->withoutOverlapping();

Schedule::command('story:post-pinterest-pin')
    ->everyFiveMinutes()
    ->between('00:00', '03:00')
    ->timezone('UTC')
    ->withoutOverlapping();

// Scheduler 4: flip scheduled dashboard content to published, every 10 minutes
Schedule::command('content:publish-due')
    ->everyTenMinutes()
    ->withoutOverlapping();
