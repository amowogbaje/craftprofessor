<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidStoryVerseUrlException;
use App\Exceptions\StoryVerseStoryNotFoundException;
use App\Models\User;
use App\Services\StoryVerseImportService;
use Illuminate\Console\Command;

/**
 * php artisan story:import-storyverse shadow-of-the-sentinel-2-the-call-beyond-the-veil --user=3
 * php artisan story:import-storyverse https://storyverse.amowogbaje.com/stories/shadow-of-the-sentinel-2-the-call-beyond-the-veil
 *
 * Same logic as POST /api/story-series/import-storyverse. --user is
 * optional; omit it to import as an unowned/house series.
 */
class ImportStoryVerseSeries extends Command
{
    protected $signature = 'story:import-storyverse
        {input : A StoryVerse story URL or bare slug}
        {--user= : User ID to own the imported series/episodes}';

    protected $description = 'Import a series and all its episodes from StoryVerse\'s /stories/{slug}/json endpoint.';

    public function handle(StoryVerseImportService $service): int
    {
        $user = $this->option('user') ? User::find($this->option('user')) : null;

        if ($this->option('user') && !$user) {
            $this->error("No user found with id {$this->option('user')}.");
            return self::FAILURE;
        }

        try {
            $series = $service->importFromInput($this->argument('input'), $user);
        } catch (InvalidStoryVerseUrlException $e) {
            $this->error("Invalid input: {$e->getMessage()}");
            return self::FAILURE;
        } catch (StoryVerseStoryNotFoundException $e) {
            $this->error("Not found: {$e->getMessage()}");
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Import failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info("Imported '{$series->title}' (series_id {$series->id}) with {$series->stories->count()} episode(s):");
        foreach ($series->stories as $story) {
            $this->line("  Episode {$story->episode_number}: {$story->title} — {$story->story_link}");
        }

        return self::SUCCESS;
    }
}
