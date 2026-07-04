<?php

use Illuminate\Support\Facades\Schedule;

// Scheduler 1: fetch story_text from Medium every 5 minutes.
// Schedule::command('story:fetch-medium-text')
//     ->everyFiveMinutes()
//     ->withoutOverlapping();

// Scheduler 2: generate the 10 image prompts + pinterest metadata.
Schedule::command('story:generate-image-prompts')
    ->everyFifteenMinutes()
    ->withoutOverlapping('story-image-pipeline');

// Scheduler 3: generate up to 3 images/day, checked frequently so it fires
Schedule::command('story:generate-images')
    ->everyFifteenMinutes()
    ->withoutOverlapping('story-image-pipeline');

// Scheduler 4: exactly 2 Pinterest posts per day, at fixed times.
// Schedule::command('story:post-pinterest-pin')
//     ->dailyAt('10:00')
//     ->withoutOverlapping();

// Schedule::command('story:post-pinterest-pin')
//     ->dailyAt('18:00')
//     ->withoutOverlapping();
