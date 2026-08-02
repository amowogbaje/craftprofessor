<?php

namespace App\Services\SocialPlatforms;

use App\Models\StoryImagePrompt;
use App\Models\Video;
use App\Services\SocialPlatforms\Contracts\PublishesImages;
use App\Services\SocialPlatforms\Contracts\PublishesVideos;
use App\Services\SocialPlatforms\DTO\SocialPostResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ⚠ SETUP REQUIRED before this can post for real:
 *  - An Instagram **Business or Creator** account linked to a Facebook
 *    Page, and a Meta app that's passed App Review for
 *    instagram_content_publish (personal accounts cannot be posted to via
 *    the API at all).
 *  - social_accounts.provider_user_id must hold the Instagram *Business*
 *    account id (not the Facebook Page id, not the @handle) — this is
 *    what {ig-user-id} below refers to.
 *  - Both image_url and video_url on the underlying model must be public,
 *    directly-fetchable URLs — Instagram's servers fetch the media
 *    themselves, no bytes are uploaded from here.
 *  - Content publishing has a rate limit (25 posts/24h per IG account).
 */
class InstagramPlatform extends AbstractSocialPlatform implements PublishesImages, PublishesVideos
{
    private const MAX_STATUS_POLLS = 10;
    private const POLL_DELAY_SECONDS = 3;

    public function name(): string
    {
        return 'instagram';
    }

    public function publishImage(StoryImagePrompt $imagePrompt): SocialPostResult
    {
        try {
            $containerId = $this->createContainer([
                'image_url' => $imagePrompt->image_generated_url,
                'caption' => $this->buildCaption($imagePrompt->pinterest_title, $imagePrompt->pinterest_description),
            ]);

            $mediaId = $this->publishContainer($containerId);

            $this->recordPost(['story_image_prompt_id' => $imagePrompt->id, 'status' => 'posted', 'external_post_id' => $mediaId]);
            return SocialPostResult::success($mediaId);
        } catch (\Throwable $e) {
            $this->log()->error('InstagramPlatform: publishImage failed', ['error' => $e->getMessage()]);
            $this->recordPost(['story_image_prompt_id' => $imagePrompt->id, 'status' => 'failed', 'error' => $e->getMessage()]);
            return SocialPostResult::failure($e->getMessage());
        }
    }

    public function publishVideo(Video $video): SocialPostResult
    {
        try {
            $containerId = $this->createContainer([
                'media_type' => 'REELS',
                'video_url' => $video->video_url,
                'caption' => $video->caption ?? '',
            ]);

            $this->waitForContainerReady($containerId);
            $mediaId = $this->publishContainer($containerId);

            $this->recordPost(['video_id' => $video->id, 'status' => 'posted', 'external_post_id' => $mediaId]);
            return SocialPostResult::success($mediaId);
        } catch (\Throwable $e) {
            $this->log()->error('InstagramPlatform: publishVideo failed', ['video_id' => $video->id, 'error' => $e->getMessage()]);
            $this->recordPost(['video_id' => $video->id, 'status' => 'failed', 'error' => $e->getMessage()]);
            return SocialPostResult::failure($e->getMessage());
        }
    }

    protected function createContainer(array $fields): string
    {
        $response = $this->http()->post("{$this->baseUrl()}/media", $fields);

        if ($response->failed()) {
            throw new RuntimeException("Instagram media container creation failed: {$response->body()}");
        }

        $id = $response->json('id');
        if (!$id) {
            throw new RuntimeException('Instagram did not return a container id.');
        }

        return $id;
    }

    /** Video containers process asynchronously — poll status_code until FINISHED before publishing. */
    protected function waitForContainerReady(string $containerId): void
    {
        for ($i = 0; $i < self::MAX_STATUS_POLLS; $i++) {
            $status = $this->http()->get("{$this->baseUrl()}/{$containerId}", ['fields' => 'status_code'])->json('status_code');

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR') {
                throw new RuntimeException('Instagram video container failed processing.');
            }

            sleep(self::POLL_DELAY_SECONDS);
        }

        throw new RuntimeException('Instagram video container did not finish processing in time.');
    }

    protected function publishContainer(string $containerId): string
    {
        $response = $this->http()->post("{$this->baseUrl()}/media_publish", ['creation_id' => $containerId]);

        if ($response->failed()) {
            throw new RuntimeException("Instagram media publish failed: {$response->body()}");
        }

        $id = $response->json('id');
        if (!$id) {
            throw new RuntimeException('Instagram did not return a published media id.');
        }

        return $id;
    }

    protected function buildCaption(?string $title, ?string $description): string
    {
        return trim(collect([$title, $description])->filter()->implode("\n\n"));
    }

    protected function baseUrl(): string
    {
        $version = config('services.instagram.graph_api_version', 'v21.0');
        $igUserId = $this->account->provider_user_id;

        return "https://graph.facebook.com/{$version}/{$igUserId}";
    }

    protected function http()
    {
        return Http::withToken($this->account->access_token)->timeout(30);
    }
}
