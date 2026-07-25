<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\Story;
use App\Services\MediumJsonTextProcessorService;

class ProcessMediumJsonStories extends Command
{
    protected $signature = 'stories:process-medium-json {--story_id=}';
    protected $description = 'Extract text from stored Medium JSON';

    public function handle(MediumJsonTextProcessorService $processor)
    {
        $query = Story::whereNotNull('story_text_json')
            ->whereNull('processed_at');

        if ($id = $this->option('story_id')) {
            $query->where('id', $id);
        }

        $query->chunkById(50, function ($stories) use ($processor) {
            foreach ($stories as $story) {
                try {
                    $text = $processor->process($story);

                    $story->update([
                        'story_text' => $text,
                        'text_source' => 'json',
                        'processed_at' => now(),
                    ]);

                    $this->info("Processed story {$story->id}");
                } catch (\Throwable $e) {
                    $this->error("Story {$story->id} failed: {$e->getMessage()}");
                }
            }
        });
    }
}