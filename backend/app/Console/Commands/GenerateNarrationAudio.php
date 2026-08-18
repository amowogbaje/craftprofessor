<?php

namespace App\Console\Commands;

use App\Exceptions\UserGenerationLimitReached;
use App\Models\StoryImagePrompt;
use App\Services\NarrationAudioService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler 2b — companion to story:generate-images. Generates narration
 * audio (see NarrationAudioService) for scenes that already have a
 * `narration` line but no `narration_audio_url` yet. Independent of image
 * generation so narration can be produced in parallel rather than waiting
 * on the image queue.
 */
class GenerateNarrationAudio extends Command
{
    protected $signature = 'story:generate-narration-audio {--limit=10 : Max scenes to process this run}';
    protected $description = 'Generate narration audio for scenes that have a narration line but no audio yet.';

    protected Collection $blockedUserIds;

    public function handle(NarrationAudioService $service): int
    {
        $this->blockedUserIds = collect();
        $limit = (int) $this->option('limit');
        $processed = 0;

        while ($processed < $limit) {
            $scene = StoryImagePrompt::awaitingNarrationAudio()
                ->when($this->blockedUserIds->isNotEmpty(), fn ($q) => $q->whereNotIn('user_id', $this->blockedUserIds->unique()))
                ->oldest('id')
                ->first();

            if (!$scene) {
                break;
            }

            try {
                if ($service->generate($scene)) {
                    $this->info("Generated narration audio for scene #{$scene->id}");
                    $processed++;
                } else {
                    // Failed generation already logged inside the service;
                    // generation_attempts was bumped so it'll drop out of
                    // scopeAwaitingNarrationAudio once it hits the cap.
                    $processed++;
                }
            } catch (UserGenerationLimitReached $e) {
                $this->blockedUserIds->push($e->userId);
                Log::info('GenerateNarrationAudio: user blocked this run', ['user_id' => $e->userId, 'reason' => $e->getMessage()]);
            }
        }

        if ($processed > 0) {
            $this->info("Run complete: {$processed} scenes processed.");
        }

        return self::SUCCESS;
    }
}
