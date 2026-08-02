<?php

namespace App\Ai\Agents;

use App\Models\StoryImagePrompt;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Given one finished image/pin (its prompt, caption, Pinterest title +
 * description) and the account's existing board list, decides which
 * board(s) — up to 3 — this pin should go out to. Can either reuse an
 * existing board by name, or propose a brand-new one (name + description)
 * when nothing in the list is really a fit; PinterestBoardSelectionService
 * is responsible for actually creating any proposed new board on Pinterest
 * before posting.
 */
class BoardSelectionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        protected StoryImagePrompt $imagePrompt,
        protected Collection $existingBoards, // Collection<PinterestBoard>
    ) {
    }

    protected function boardList(): string
    {
        if ($this->existingBoards->isEmpty()) {
            return '(no boards yet — you will need to propose at least one new one)';
        }

        return $this->existingBoards->map(function ($board) {
            $topics = $board->topics ? implode(', ', $board->topics) : 'none recorded';
            return "- \"{$board->name}\" — {$board->description} (topics: {$topics})";
        })->implode("\n");
    }

    public function instructions(): Stringable|string
    {
        $story = $this->imagePrompt->story;
        $genre = $story?->series?->title ?? $story?->title ?? 'unspecified';

        return <<<INSTRUCTIONS
        You are a Pinterest marketing strategist choosing which board(s) a
        single finished pin should be published to, in order to maximize
        organic reach and keep boards thematically coherent (a well-curated
        board performs far better than a junk-drawer board).

        PIN CONTENT:
        - Image prompt: {$this->imagePrompt->prompt}
        - Caption overlay: {$this->imagePrompt->caption}
        - Pinterest title: {$this->imagePrompt->pinterest_title}
        - Pinterest description: {$this->imagePrompt->pinterest_description}
        - Story/series context: {$genre}

        EXISTING BOARDS ON THIS ACCOUNT:
        {$this->boardList()}

        RULES:
        1. Choose between 1 and 3 boards total for this pin — never 0, never
           more than 3. Posting to more than one relevant board is
           encouraged (it's free extra reach) but only include a board if
           it's a genuine thematic fit, not just to hit 3.
        2. Strongly prefer reusing an existing board when it's a reasonable
           fit — set is_new to false and reuse its "name" value EXACTLY as
           listed above (character-for-character, so we can match it back
           to the existing board).
        3. Only propose a brand-new board (is_new: true) when none of the
           existing boards genuinely fit this pin's theme/genre, or when
           there are no boards yet. New board names should be short,
           Pinterest-search-friendly, and reusable for future pins in the
           same theme (not hyper-specific to this one pin) — e.g. "Dark
           Academia Fantasy Art" not "Shadow of the Sentinel Episode 2
           Scene 3". Give it a 1-2 sentence description and 3-6 topic
           keywords a future pin could be matched against.
        4. Do not propose two new boards that are near-duplicates of each
           other or of an existing board.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'boards' => $schema->array()
                ->min(1)->max(3)
                ->items(
                    $schema->object(fn (JsonSchema $s) => [
                        'name' => $s->string()->required(),
                        'is_new' => $s->boolean()->required(),
                        // Required when is_new is true; ignored otherwise.
                        'description' => $s->string()->nullable(),
                        'topics' => $s->array()->items($s->string())->nullable(),
                    ])
                )
                ->required(),
        ];
    }

    public function messages(): iterable
    {
        return [];
    }
}
