<?php

namespace Database\Seeders;

use App\Models\Story;
use App\Models\StorySeries;
use Illuminate\Database\Seeder;

/**
 * Seeds stories with ONLY medium_link set — story_text stays null on
 * purpose so Scheduler 1 has real work to pick up on the next run.
 *
 * All links below are treated as episodes of ONE linked series, in the
 * order listed (episode_number = 1, 2, 3...). Characters introduced in
 * episode 1 will be reused in episode 2, 3, etc. instead of recreated —
 * see Story::knownCharacters().
 *
 * Edit $links directly, or set MEDIUM_SEED_LINKS as a comma-separated env
 * var to override without touching this file.
 */
class StorySeeder extends Seeder
{
    public function run(): void
    {
        $links = [
                'https://medium.com/@amowogbajeflorence/episode-1-whispers-in-the-valley-75f4145060d0',
                'https://medium.com/@amowogbajeflorence/episode-2-the-song-and-the-princess-a7aebf219e55',
                'https://medium.com/@amowogbajeflorence/episode-3-the-poisoned-crown-fd4c01a86f6c',
                'https://medium.com/@amowogbajeflorence/episode-4-the-shadow-that-roared-15c632a8f076',
                'https://medium.com/@amowogbajeflorence/episode-5-rise-of-the-flame-queen-e84ff6e406c2',
                'https://medium.com/@amowogbajeflorence/episode-6-the-gathering-storm-fdd204e7b064',
                'https://medium.com/@amowogbajeflorence/episode-7-the-covenant-before-the-fire-a4d7306b38ef',
                'https://medium.com/@amowogbajeflorence/episode-8-fire-from-the-mountain-25d76e5d6262',
                'https://medium.com/@amowogbajeflorence/episode-9-the-kingmakers-crown-0a011b9198b1',
                'https://medium.com/@amowogbajeflorence/episode-10-the-return-of-shadows-9439ab469e63',
                'https://medium.com/@amowogbajeflorence/episode-11-shadows-within-the-throne-room-3fc187f5104c',
                'https://medium.com/@amowogbajeflorence/episode-12-the-siege-of-secrets-ca691fd8ebeb',
                'https://medium.com/@amowogbajeflorence/episode-13-the-crown-in-the-dust-08b0c92894d3',
                'https://medium.com/@amowogbajeflorence/episode-14-the-tower-and-the-thorn-ff298bfc059b',
                'https://medium.com/@amowogbajeflorence/episode-15-the-face-in-the-roots-ab97440a68f3',
                'https://medium.com/@amowogbajeflorence/episode-16-thrones-of-dust-and-flame-76dc4caf474e',
                'https://medium.com/@amowogbajeflorence/episode-17-the-prince-from-the-north-70dd2b33da72',
                'https://medium.com/@amowogbajeflorence/episode-18-layalis-dream-a3e193b3467e',
                'https://medium.com/@amowogbajeflorence/episode-19-the-war-of-thrones-c8edcffcf206',
                'https://amowogbajeflorence.medium.com/episode-20-the-crown-of-flame-82003ded3048'
            ];

        $series = StorySeries::firstOrCreate(
            ['slug' => \Illuminate\Support\Str::slug(env('MEDIUM_SEED_SERIES_TITLE', 'The Song of Kael'))],
            ['title' => env('MEDIUM_SEED_SERIES_TITLE', 'The Song of Kael')]
        );

        foreach ($links as $index => $link) {
            $story = Story::firstOrCreate(
                ['medium_link' => $link],
                ['series_id' => $series->id, 'episode_number' => $index + 1]
            );

            // If it already existed as standalone, attach it to the series now.
            if (is_null($story->series_id)) {
                $story->update(['series_id' => $series->id, 'episode_number' => $index + 1]);
            }
        }

        $this->command->info(count($links) . " story link(s) seeded as episodes of '{$series->title}'.");
    }
}
