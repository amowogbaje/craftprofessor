<?php

namespace App\Jobs;

use App\Exceptions\UserGenerationLimitReached;
use App\Models\StoryImagePrompt;
use App\Services\ImageGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateSceneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public StoryImagePrompt $imagePrompt) {}

    public function handle(ImageGeneratorService $service)
    {
        try {
            $service->generateImage($this->imagePrompt);
        } catch (UserGenerationLimitReached) {
            // Not a real failure — see GeneratePortraitJob for rationale.
        }
    }
}