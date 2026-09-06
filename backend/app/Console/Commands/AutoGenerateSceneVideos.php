<?php

namespace App\Console\Commands;

use App\Exceptions\InsufficientCoinsException;
use App\Exceptions\UserGenerationLimitReached;
use App\Models\StoryImagePrompt;
use App\Services\SceneVideoGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The automatic half of "AI video clip generation should be automatic or
 * manual based on the user's preference": scheduled (see routes/console.php),
 * runs continuously in the background, but ONLY ever touches scenes
 * belonging to a user who has explicitly opted in via
 * PublishSetting::auto_generate_scene_videos. Everyone else's scenes are
 * completely untouched by this command — they keep the original manual/
 * on-demand behavior via VideoController (dashboard button) exactly as
 * before this feature existed.
 *
 * Deliberately a separate command from story:generate-videos rather than
 * a flag on it: that command is documented as a manual/testing tool not on
 * the scheduler, and giving it a second, opted-in-users, scheduled
 * personality would blur that. Both ultimately call the same
 * SceneVideoGenerationService::generate(), so there's no behavioral
 * drift between the manual and automatic paths — same coin cost, same
 * daily/monthly limit enforcement (UsageLimitService, via that service),
 * same failure/retry semantics (StoryImagePrompt::hasCompletedVideo() /
 * clearVideoAttempt()).
 *
 * Cost containment: this command's own --limit only bounds how much work
 * ONE invocation does across ALL opted-in users combined (fairness: a
 * round-robin one-scene-per-user pass, not "drain user #1's entire
 * backlog before moving on"). The real per-user ceiling is each user's own
 * daily_video_limit/monthly_video_limit + coin balance, exactly as it
 * already was for manual generation — opting in doesn't raise or bypass
 * those, it just means scenes stop waiting for a click.
 */
class AutoGenerateSceneVideos extends Command
{
    protected $signature = 'story:auto-generate-videos
        {--limit=25 : Max scenes to process this run, across all opted-in users combined}';

    protected $description = 'Generate scene videos automatically for users who have opted in via publish_settings.auto_generate_scene_videos.';

    protected Collection $blockedUserIds;

    public function handle(SceneVideoGenerationService $generator): int
    {
        $this->blockedUserIds = collect();
        $limit = (int) $this->option('limit');
        $processed = 0;

        while ($processed < $limit) {
            $scene = $this->nextEligibleScene();

            if (!$scene) {
                $this->info($processed === 0
                    ? 'No opted-in user has a scene awaiting automatic video generation.'
                    : 'No more eligible scenes this run.');
                break;
            }

            $this->processScene($scene, $generator);
            $processed++;
        }

        if ($this->blockedUserIds->isNotEmpty()) {
            $this->info('Skipped users this run (limit/coins): ' . $this->blockedUserIds->unique()->implode(', '));
        }

        return self::SUCCESS;
    }

    /**
     * Oldest scene with an image, no completed video yet, belonging to a
     * user who has opted into automatic generation — round-robins across
     * users within a run via blockedUserIds the same way
     * story:generate-images/story:generate-videos already do, rather than
     * one user's whole backlog starving everyone else's turn this run.
     */
    protected function nextEligibleScene(): ?StoryImagePrompt
    {
        return StoryImagePrompt::whereNotNull('image_generated_url')
            ->whereDoesntHave('video', fn ($q) => $q->whereNotNull('video_url'))
            ->whereHas('user.publishSetting', fn ($q) => $q->where('auto_generate_scene_videos', true))
            ->when($this->blockedUserIds->isNotEmpty(), fn ($q) => $q->whereNotIn('user_id', $this->blockedUserIds->unique()))
            ->oldest('id')
            ->first();
    }

    protected function processScene(StoryImagePrompt $scene, SceneVideoGenerationService $generator): void
    {
        $user = $scene->user;

        if (!$user) {
            return;
        }

        // Stale/failed attempt, if any — see StoryImagePrompt::clearVideoAttempt().
        // hasCompletedVideo() is already guaranteed false by nextEligibleScene()'s
        // query, so any existing VideoPrompt/Video row here is necessarily a
        // dead one from a previous failed run.
        $scene->clearVideoAttempt();

        try {
            $video = $generator->generate($scene, $user, notify: true);

            $this->info("Scene #{$scene->id} (user #{$user->id}): auto-generated video #{$video->id}.");
            Log::info('story:auto-generate-videos: succeeded', [
                'story_image_prompt_id' => $scene->id,
                'user_id' => $user->id,
                'video_id' => $video->id,
            ]);
        } catch (InsufficientCoinsException $e) {
            $this->blockedUserIds->push($user->id);
            Log::info('story:auto-generate-videos: insufficient coins, skipping user for this run', ['user_id' => $user->id]);
        } catch (UserGenerationLimitReached $e) {
            $this->blockedUserIds->push($user->id);
            Log::info('story:auto-generate-videos: user hit daily/monthly video limit, skipping for this run', ['user_id' => $user->id]);
        } catch (\Throwable $e) {
            $this->error("Scene #{$scene->id}: {$e->getMessage()}");
            Log::error('story:auto-generate-videos: failed', [
                'story_image_prompt_id' => $scene->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
