<?php

use Illuminate\Support\Facades\Schedule;

// Test scheduler by logging every five minutes
// Scheduler 1: fetch story_text from Medium every 5 minutes.
// Schedule::command('story:fetch-medium-text')
//     ->everyFiveMinutes()
//     ->withoutOverlapping();

// Scheduler 2: generate the 10 image prompts + pinterest metadata.
Schedule::command('story:generate-image-prompts')->everyFifteenMinutes()->withoutOverlapping();

// Scheduler 3: generate up to 3 images/day, checked frequently so it fires
// $schedule->command('story:generate-images --limit=50 --time-budget=50')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();

// Scheduler 4: exactly 2 Pinterest posts per day, at fixed times.
// Schedule::command('story:post-pinterest-pin')
//     ->dailyAt('10:00')
//     ->withoutOverlapping();

// Schedule::command('story:post-pinterest-pin')
//     ->dailyAt('18:00')
//     ->withoutOverlapping();

// Scheduler 5: flip scheduled dashboard content (images/videos) to
// published once their scheduled_at time arrives.
Schedule::command('content:publish-due')
    ->everyFiveMinutes()
    ->withoutOverlapping();
