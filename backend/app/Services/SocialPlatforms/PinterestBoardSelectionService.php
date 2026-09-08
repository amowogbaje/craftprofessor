<?php

namespace App\Services\SocialPlatforms;

use App\Ai\Agents\BoardSelectionAgent;
use App\Models\PinterestBoard;
use App\Models\SocialAccount;
use App\Models\StoryImagePrompt;
use App\Services\PinterestService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single Responsibility: figure out which 1-3 PinterestBoard rows a given
 * image should be posted to, and make sure any AI-proposed new board
 * actually exists on Pinterest (creating it if not) before handing back
 * the final list. Doesn't post anything itself — that's PinterestPlatform.
 */
class PinterestBoardSelectionService
{
    public const MAX_BOARDS_PER_PIN = 3;

    /** @return Collection<int, PinterestBoard> */
    public function resolve(StoryImagePrompt $imagePrompt, SocialAccount $account): Collection
    {
        $storyBoard = $this->resolveStoryOverride($imagePrompt);

        if ($storyBoard) {
            return collect([$storyBoard]);
        }

        if (!$account->isDynamicBoardPosting()) {
            $fixed = $account->preferredBoards()->active()->limit(self::MAX_BOARDS_PER_PIN)->get();

            if ($fixed->isNotEmpty()) {
                return $fixed;
            }

            Log::channel('pinterest')->warning(
                'PinterestBoardSelectionService: account is in fixed mode but has no active preferred boards — falling back to dynamic selection for this pin',
                ['user_id' => $account->user_id]
            );
            // fall through to dynamic selection below rather than posting nowhere
        }

        return $this->resolveDynamically($imagePrompt, $account);
    }

    /**
     * A story can pin a specific board for everything it posts
     * (Story::pinterest_board_id) instead of following the account's
     * fixed/dynamic setup — this is what makes that override take effect.
     * Only used when the story's chosen board is still active; a
     * deactivated board (PinterestBoard::is_active = false) is treated
     * the same as "no override chosen," falling through to the account
     * default below, rather than silently pinning to a board the user
     * retired.
     */
    protected function resolveStoryOverride(StoryImagePrompt $imagePrompt): ?PinterestBoard
    {
        $board = $imagePrompt->story?->pinterestBoard;

        return ($board && $board->is_active) ? $board : null;
    }


    /** @return Collection<int, PinterestBoard> */
    protected function resolveDynamically(StoryImagePrompt $imagePrompt, SocialAccount $account): Collection
    {
        $existingBoards = $account->boards()->active()->get();

        $decision = (new BoardSelectionAgent($imagePrompt, $existingBoards))
            ->prompt('Choose the best board(s) for this pin.');

        $picks = collect($decision['boards'] ?? [])->take(self::MAX_BOARDS_PER_PIN);

        if ($picks->isEmpty()) {
            Log::channel('pinterest')->warning('PinterestBoardSelectionService: agent returned no boards, falling back to first active board', [
                'story_image_prompt_id' => $imagePrompt->id,
            ]);

            return $existingBoards->take(1);
        }

        return $picks
            ->map(fn (array $pick) => $this->resolveOnePick($pick, $existingBoards, $account))
            ->filter()
            ->unique('id')
            ->values();
    }

    protected function resolveOnePick(array $pick, Collection $existingBoards, SocialAccount $account): ?PinterestBoard
    {
        $name = trim((string) ($pick['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        if (empty($pick['is_new'])) {
            $match = $existingBoards->first(fn (PinterestBoard $b) => Str::lower($b->name) === Str::lower($name));

            if ($match) {
                return $match;
            }

            Log::channel('pinterest')->warning('PinterestBoardSelectionService: agent picked an existing board name that does not match any known board — creating it instead', [
                'name' => $name,
            ]);
            // fall through and create it, rather than silently dropping this pick
        }

        return $this->createBoard($account, $name, $pick['description'] ?? null, $pick['topics'] ?? []);
    }

    protected function createBoard(SocialAccount $account, string $name, ?string $description, array $topics): ?PinterestBoard
    {
        try {
            $created = PinterestService::forAccount($account)->createBoard($name, $description);
        } catch (\Throwable $e) {
            Log::channel('pinterest')->error('PinterestBoardSelectionService: failed to create AI-proposed board on Pinterest', [
                'user_id' => $account->user_id,
                'name' => $name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return PinterestBoard::create([
            'user_id' => $account->user_id,
            'social_account_id' => $account->id,
            'external_board_id' => $created['id'],
            'name' => $created['name'] ?? $name,
            'description' => $description,
            'topics' => $topics,
            'source' => 'ai_created',
            'is_active' => true,
        ]);
    }
}
