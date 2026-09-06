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

// Scheduler 2b: narration audio for scenes, independent of the image queue above
Schedule::command('story:generate-narration-audio --limit=10')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// Scheduler 2c: automatic per-scene video clips — opt-in only (see
// PublishSetting::auto_generate_scene_videos / AutoGenerateSceneVideos).
// Users who haven't turned this on are entirely unaffected by this
// entry; their scenes stay on the manual/on-demand path
// (VideoController) exactly as before this feature existed. Same 15-min
// cadence as images/narration above since video generation only ever
// picks up scenes that already have an image, so there's no reason for
// it to poll faster than the thing that feeds it.
Schedule::command('story:auto-generate-videos --limit=25')
    ->everyFifteenMinutes()
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

// Scheduler 3c: assembled story videos (the narrated + captioned "mixed
// copy") — separate cadence/cap from per-scene pins above, since these are
// far less frequent (one per finished story, not one per scene). Runs less
// often; there's no need to check every 5 minutes for something this rare.
// Same overnight-window split as Scheduler 3b above (two registrations
// rather than one between() spanning midnight, matching that established
// pattern in this file).
Schedule::command('story:post-pinterest-story-video')
    ->everyThirtyMinutes()
    ->between('23:00', '23:59')
    ->timezone('UTC')
    ->withoutOverlapping();

Schedule::command('story:post-pinterest-story-video')
    ->everyThirtyMinutes()
    ->between('00:00', '03:00')
    ->timezone('UTC')
    ->withoutOverlapping();

// Scheduler 3d: 1 YouTube Short/day — reuses the same assembled StoryVideo
// as Scheduler 3c above, just posted to a different platform with its own
// daily cap (services.youtube.max_shorts_per_user_per_day). Runs once,
// inside the same overnight posting window as the rest of Scheduler 3,
// rather than every 30 minutes like 3c — with a cap of 1/day the first
// invocation each day is normally the only one that does anything, so
// there's no need to poll as often.
Schedule::command('youtube:post-daily-short')
    ->dailyAt('23:10')
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
