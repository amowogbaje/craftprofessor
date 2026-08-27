<?php

namespace App\Console\Commands;

use App\Exceptions\InsufficientCoinsException;
use App\Models\StoryImagePrompt;
use App\Services\UsageLimitService;
use App\Services\VideoGeneratorService;
use App\Services\VideoPromptService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Manual/CLI counterpart to POST /api/story-image-prompts/{id}/video +
 * GenerateVideoJob — runs the exact same two-step pipeline (VideoPromptService
 * writes the motion prompt, VideoGeneratorService calls the configured
 * VIDEO_PROVIDER) but synchronously in the current process, so you can watch
 * it happen and see the real exception instead of digging through a queue
 * worker's log after the fact. Built for testing a provider (e.g. Agnes AI)
 * end-to-end from the CLI without needing `queue:work` running.
 *
 * Every meaningful step is logged at INFO, every failure at ERROR, both to
 * the console and storage/logs — same channel VideoPromptService/
 * VideoGeneratorService already write to, so `tail -f storage/logs/laravel-*.log`
 * shows the full picture including provider-specific errors
 * (e.g. "AgnesAiVideoProvider: submit failed").
 *
 * Not on the scheduler — this is a manual/testing tool, not part of the
 * automated pipeline (that's still request -> GenerateVideoJob -> queue).
 */
class GenerateStoryVideos extends Command
{
    protected $signature = 'story:generate-videos
        {--scene= : Story image prompt (scene) id to force — skips the queue-picking logic entirely}
        {--user= : Only consider scenes belonging to this user id}
        {--limit=1 : Max scenes to process this run (ignored if --scene is given)}
        {--force : With --scene, clear any existing video attempt first and regenerate (same as the API\'s regenerate endpoint)}';

    protected $description = 'Generate video(s) for scene(s) synchronously, with verbose logging — for testing VIDEO_PROVIDER (Veo/Agnes) from the CLI.';

    /** User ids that hit a limit/coins wall this run — skip their rows for the rest of the run, same fairness rule as story:generate-images. */
    protected Collection $blockedUserIds;

    public function handle(VideoPromptService $promptService, VideoGeneratorService $videoService, UsageLimitService $limits): int
    {
        $this->blockedUserIds = collect();
        $provider = config('ai.default_video_provider') ?: 'veo';
        $this->info("VIDEO_PROVIDER = {$provider}");

        $sceneOption = $this->option('scene');

        if ($sceneOption) {
            $scene = StoryImagePrompt::find($sceneOption);

            if (!$scene) {
                $this->error("No story_image_prompt with id {$sceneOption}.");
                return self::FAILURE;
            }

            if ($this->option('force') && $scene->videoPrompt) {
                $this->line("Clearing previous video attempt for scene #{$scene->id}...");
                \App\Models\Video::where('video_prompt_id', $scene->videoPrompt->id)->delete();
                $scene->videoPrompt->delete();
                $scene->refresh();
            }

            $this->processScene($scene, $promptService, $videoService, $limits);
            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $processed = 0;

        while ($processed < $limit) {
            $scene = $this->nextEligibleScene();

            if (!$scene) {
                $this->info($processed === 0 ? 'No scenes are ready for video generation.' : 'No more eligible scenes.');
                break;
            }

            $this->processScene($scene, $promptService, $videoService, $limits);
            $processed++;
        }

        if ($this->blockedUserIds->isNotEmpty()) {
            $this->info('Skipped users this run (limit/coins): ' . $this->blockedUserIds->unique()->implode(', '));
        }

        return self::SUCCESS;
    }

    /**
     * Oldest scene with an image but no video attempt yet (whether that
     * attempt succeeded or failed — same "already requested" rule the API
     * endpoint uses, see VideoController::store), excluding users already
     * blocked this run and optionally filtered to --user.
     */
    protected function nextEligibleScene(): ?StoryImagePrompt
    {
        return StoryImagePrompt::whereNotNull('image_generated_url')
            ->whereDoesntHave('videoPrompt')
            ->when($this->option('user'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($this->blockedUserIds->isNotEmpty(), fn ($q) => $q->whereNotIn('user_id', $this->blockedUserIds->unique()))
            ->oldest('id')
            ->first();
    }

    /** @return bool true if a video was actually generated, false if skipped/blocked/failed */
    protected function processScene(
        StoryImagePrompt $scene,
        VideoPromptService $promptService,
        VideoGeneratorService $videoService,
        UsageLimitService $limits,
    ): bool {
        $user = $scene->user;

        if (!$user) {
            $this->warn("Scene #{$scene->id} has no owning user — skipping.");
            return false;
        }

        $this->line("--- Scene #{$scene->id} (user #{$user->id}) ---");
        Log::info('story:generate-videos: starting', ['story_image_prompt_id' => $scene->id, 'user_id' => $user->id]);

        if (empty($scene->image_generated_url)) {
            $this->warn('  No image generated yet for this scene — nothing to animate.');
            Log::warning('story:generate-videos: no source image', ['story_image_prompt_id' => $scene->id]);
            return false;
        }

        if ($scene->videoPrompt()->exists()) {
            $this->warn('  A video has already been requested for this scene.');
            return false;
        }

        if (!$limits->canGenerateVideo($user)) {
            $this->warn("  User #{$user->id} hit their daily/monthly video limit.");
            Log::info('story:generate-videos: daily/monthly limit reached', ['user_id' => $user->id]);
            $this->blockedUserIds->push($user->id);
            return false;
        }

        $required = (int) config('coins.costs.video_prompt') + (int) config('coins.costs.video_generation');

        if ($user->wallet->balance < $required) {
            $this->warn("  User #{$user->id} has insufficient coins (needs {$required}, has {$user->wallet->balance}).");
            Log::info('story:generate-videos: insufficient coins', ['user_id' => $user->id, 'required' => $required, 'available' => $user->wallet->balance]);
            $this->blockedUserIds->push($user->id);
            return false;
        }

        try {
            $this->line('  Writing motion prompt (VideoPromptService)...');
            $videoPrompt = $promptService->generate($scene, $user);
            $this->info("  Motion prompt: \"{$videoPrompt->prompt}\"");

            $this->line("  Calling video provider ({$videoService->providerName()})...");
            $video = $videoService->generate($videoPrompt, $user);

            if ($video && $video->video_url) {
                $this->info("  Done — video #{$video->id}: {$video->video_url}");
                Log::info('story:generate-videos: succeeded', [
                    'story_image_prompt_id' => $scene->id,
                    'video_id' => $video->id,
                    'video_url' => $video->video_url,
                    'provider' => $video->provider,
                ]);
                return true;
            }

            $this->error('  Video provider returned no video (see log for details).');
            return false;
        } catch (InsufficientCoinsException $e) {
            $this->warn("  {$e->getMessage()}");
            $this->blockedUserIds->push($user->id);
            return false;
        } catch (\Throwable $e) {
            $this->error("  Failed: {$e->getMessage()}");
            Log::error('story:generate-videos: failed', [
                'story_image_prompt_id' => $scene->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
