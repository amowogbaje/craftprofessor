<?php

namespace App\Services\SocialPlatforms;

use App\Models\StoryImagePrompt;
use App\Services\PinterestService;
use App\Services\SocialPlatforms\Contracts\PublishesImages;
use App\Services\SocialPlatforms\Contracts\PublishesRawImage;
use App\Services\SocialPlatforms\Contracts\PublishesRawVideo;
use App\Services\SocialPlatforms\Contracts\SupportsMultipleBoards;
use App\Services\SocialPlatforms\DTO\SocialPostResult;
use Illuminate\Support\Collection;

/**
 * Adapter, not a rewrite: all the actual Pinterest HTTP/OAuth work (token
 * refresh, board CRUD, pin creation) already exists and is battle-tested
 * in PinterestService — this class exists purely so PostPinterestPins (and
 * anything else) can depend on the platform-agnostic PublishesImages
 * contract instead of the concrete PinterestService, and so "post the same
 * image to up to 3 boards" has a home that isn't the command itself.
 */
class PinterestPlatform extends AbstractSocialPlatform implements PublishesImages, SupportsMultipleBoards, PublishesRawImage, PublishesRawVideo
{
    public function name(): string
    {
        return 'pinterest';
    }

    public function resolveBoardsForImage(StoryImagePrompt $imagePrompt): Collection
    {
        return app(PinterestBoardSelectionService::class)->resolve($imagePrompt, $this->account);
    }

    /**
     * Interface-required single-result method — posts to the first
     * resolved board only. Most callers that actually care about
     * Pinterest's "post to several boards" behavior should call
     * publishToBoards() directly instead.
     */
    public function publishImage(StoryImagePrompt $imagePrompt): SocialPostResult
    {
        return $this->publishToBoards($imagePrompt)->first()
            ?? SocialPostResult::failure('No boards were available to post to.');
    }

    /**
     * Cause-media / any raw-URL image, posted to this account's single
     * best-guess board (its last-used/default board) rather than the
     * up-to-3-board fan-out publishToBoards() does for StoryImagePrompt —
     * a Cause broadcast fires once per member's account, not per board.
     */
    public function publishRawImage(string $imageUrl, string $title, ?string $details = null, ?string $linkUrl = null): SocialPostResult
    {
        try {
            $pinterest = PinterestService::forAccount($this->account);
            $boardId = $this->account->board_id ?? $pinterest->getLastBoardId();

            $pinId = $pinterest->postRawImagePinToBoard($title, $details, $linkUrl, $imageUrl, $boardId);

            return SocialPostResult::success($pinId);
        } catch (\Throwable $e) {
            $this->log()->error('PinterestPlatform: publishRawImage failed', ['error' => $e->getMessage()]);
            return SocialPostResult::failure($e->getMessage());
        }
    }

    public function publishRawVideo(string $videoUrl, string $caption, ?string $linkUrl = null): SocialPostResult
    {
        try {
            $pinterest = PinterestService::forAccount($this->account);
            $boardId = $this->account->board_id ?? $pinterest->getLastBoardId();

            $mediaId = $pinterest->registerAndUploadVideo($videoUrl);
            $pinId = $pinterest->postVideoPinToBoard($caption, null, $linkUrl, $mediaId, $boardId);

            return SocialPostResult::success($pinId);
        } catch (\Throwable $e) {
            $this->log()->error('PinterestPlatform: publishRawVideo failed', ['error' => $e->getMessage()]);
            return SocialPostResult::failure($e->getMessage());
        }
    }

    /** @return Collection<int, SocialPostResult> one result per board posted to (up to 3). */
    public function publishToBoards(StoryImagePrompt $imagePrompt): Collection
    {
        $boards = $this->resolveBoardsForImage($imagePrompt);
        $pinterest = PinterestService::forAccount($this->account);

        if ($boards->isEmpty()) {
            $result = SocialPostResult::failure('No Pinterest boards were resolved for this image.');
            $this->recordPost([
                'story_image_prompt_id' => $imagePrompt->id,
                'status' => 'failed',
                'error' => $result->error,
            ]);
            return collect([$result]);
        }

        return $boards->map(function ($board) use ($imagePrompt, $pinterest) {
            try {
                $pinId = $pinterest->postPinToBoard($imagePrompt, $board->external_board_id);

                $this->recordPost([
                    'story_image_prompt_id' => $imagePrompt->id,
                    'pinterest_board_id' => $board->id,
                    'status' => 'posted',
                    'external_post_id' => $pinId,
                ]);

                return SocialPostResult::success($pinId, $board->id);
            } catch (\Throwable $e) {
                $this->recordPost([
                    'story_image_prompt_id' => $imagePrompt->id,
                    'pinterest_board_id' => $board->id,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);

                return SocialPostResult::failure($e->getMessage(), $board->id);
            }
        });
    }
}
