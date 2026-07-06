<?php
namespace App\Console\Commands;

use App\Services\PinterestService;
use Illuminate\Console\Command;

class TestPinterestPin extends Command
{
    protected $signature = 'test:pinterest-pin {image} {--title=Test Pin}';
    protected $description = 'Manually test posting a pin from public/images';

    public function handle(PinterestService $pinterest)
    {
        $imagePath = 'images/' . $this->argument('image');
        $title = $this->option('title');

        $this->info("Attempting to post: {$imagePath}...");

        try {
            $pinId = $pinterest->postLocalImagePin(
                $title,
                'This is a test description generated from CLI.',
                'https://medium.com/@amowogbajeflorence/episode-1-whispers-in-the-valley-75f4145060d0',
                $imagePath
            );
            $this->info("Successfully posted! Pin ID: {$pinId}");
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}