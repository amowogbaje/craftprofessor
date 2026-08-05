<?php

use Illuminate\Support\Facades\Schedule;



// Scheduler 1: check for new image prompts every 30 minutes
Schedule::command('story:generate-image-prompts')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('images:cleanup-published')
    ->daily()
    ->withoutOverlapping();

// Delete images + prompt rows 10 days after they were posted to Pinterest.
// Runs at 04:00 UTC, after the last posting window (ends 03:00) closes.
// --force is required here: this is non-interactive, and the command
// prompts for confirmation unless --force/--dry-run is passed.
Schedule::command('images:cleanup-posted-prompts --days=10 --force')
    ->dailyAt('04:00')
    ->timezone('UTC')
    ->withoutOverlapping();

// Scheduler 2: generate up to 3 images/day — every 15 min, active for a 4-hour window
Schedule::command('story:generate-images --limit=5 --time-budget=50')
    ->everyFifteenMinutes()
    // ->between('08:00', '12:00') // 4-hour window — adjust to taste
    ->withoutOverlapping(10)
    ->runInBackground();

// Scheduler 3a: refresh Pinterest tokens ~10 min before the posting window
// opens, with a buffer wide enough to cover the whole window below (23:00
// through 03:00 = up to 4h, so 300 min / 5h of headroom).
Schedule::command('pinterest:refresh-tokens --buffer=300')
    ->dailyAt('22:50')
    ->timezone('UTC')
    ->withoutOverlapping();

// Scheduler 3b: 2 Pinterest posts/day — every 5 min, active for a 5-hour window
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

// Scheduler 5: post any due Cause broadcasts, every 5 minutes
Schedule::command('causes:post-due')
    ->everyFiveMinutes()
    ->withoutOverlapping();
