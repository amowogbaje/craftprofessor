<?php

use Illuminate\Support\Facades\Schedule;

// Scheduler 1: fetch story_text from Medium every 5 minutes.
// Schedule::command('story:fetch-medium-text')
//     ->everyFiveMinutes()
//     ->withoutOverlapping();

// Scheduler 2: generate the 10 image prompts + pinterest metadata.
// No cadence was specified beyond "check for ready stories" — running it
// every 10 minutes; tune as needed.
Schedule::command('story:generate-image-prompts')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Scheduler 3: generate up to 3 images/day, checked frequently so it fires
// soon after a prompt becomes available.
Schedule::command('story:generate-images')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Scheduler 4: exactly 2 Pinterest posts per day, at fixed times.
Schedule::command('story:post-pinterest-pin')
    ->dailyAt('10:00')
    ->withoutOverlapping();

Schedule::command('story:post-pinterest-pin')
    ->dailyAt('18:00')
    ->withoutOverlapping();
