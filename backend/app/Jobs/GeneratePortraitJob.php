<?php

namespace App\Jobs;

use App\Exceptions\UserGenerationLimitReached;
use App\Models\Character;
use App\Services\ImageGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GeneratePortraitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Character $character) {}

    public function handle(ImageGeneratorService $service)
    {
        try {
            $service->generateCharacterImage($this->character);
        } catch (UserGenerationLimitReached) {
            // Not a real failure — the user is just out of budget for now.
            // The character stays untouched and will be picked up again on
            // a future run once they're under their limit again.
        }
    }
}