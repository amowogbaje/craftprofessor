<?php

namespace App\Jobs;

use App\Models\Story;
use App\Services\StoryVideoAssemblyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AssembleStoryVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Generous — ffmpeg re-encoding a multi-scene video isn't fast, and
    // this also has to download every scene's image/video/audio first.
    public int $timeout = 900;

    public function __construct(public Story $story)
    {
    }

    public function handle(StoryVideoAssemblyService $assembler): void
    {
        try {
            $assembler->assemble($this->story);
        } catch (\Throwable $e) {
            // Already logged + status set to 'failed' inside the service.
            Log::error('AssembleStoryVideoJob: failed', [
                'story_id' => $this->story->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
