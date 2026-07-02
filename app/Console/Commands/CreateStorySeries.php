<?php

namespace App\Console\Commands;

use App\Services\StorySeriesService;
use Illuminate\Console\Command;

/**
 * php artisan story:create-series "My Series Title" \
 *   --link="https://medium.com/@you/ep-1" \
 *   --link="https://medium.com/@you/ep-2" \
 *   --link="https://medium.com/@you/ep-3"
 *
 * Creates the series and one Story per --link, in the order given
 * (episode_number = 1, 2, 3...), story_text left null so Scheduler 1 picks
 * them up as usual. Characters introduced in episode 1 will automatically
 * be reused in episodes 2, 3, etc. instead of being recreated — see
 * Story::knownCharacters(). Same logic as POST /api/story-series.
 */
class CreateStorySeries extends Command
{
    protected $signature = 'story:create-series
        {title : Title of the series}
        {--link=* : Medium links, one per episode, in order}
        {--description= : Optional series description}';

    protected $description = 'Create a series and seed its episodes (as linked stories) from Medium links.';

    public function handle(StorySeriesService $service): int
    {
        $links = $this->option('link');

        if (empty($links)) {
            $this->error('Provide at least one --link=<medium url> per episode.');
            return self::FAILURE;
        }

        $series = $service->createLinkedSeries($links, $this->argument('title'), $this->option('description'));

        foreach ($series->stories as $story) {
            $this->info("Episode {$story->episode_number}: {$story->medium_link}");
        }

        $this->info("Series '{$series->title}' created with {$series->stories->count()} episode(s) (series_id {$series->id}).");

        return self::SUCCESS;
    }
}
