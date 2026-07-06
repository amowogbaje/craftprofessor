<?php

namespace App\Jobs;

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
        // Automatically handled by your service's try/catch
        $service->generateCharacterImage($this->character);
    }
}