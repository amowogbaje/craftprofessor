<?php

namespace App\Services\SocialPlatforms;

use App\Models\StoryImagePrompt;
use App\Models\Video;
use App\Services\SocialPlatforms\Contracts\PublishesImages;
use App\Services\SocialPlatforms\Contracts\PublishesText;
use App\Services\SocialPlatforms\Contracts\PublishesVideos;
use App\Services\SocialPlatforms\DTO\SocialPostResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ⚠ SETUP REQUIRED before this can post for real:
 *  - A Facebook Page (posting as a personal profile isn't supported by the
 *    API) and a Meta app with pages_manage_posts (+ pages_read_engagement)
 *    approved in App Review.
 *  - social_accounts.access_token must be a **Page** access token, not a
 *    user token — exchange the user token for a Page token at connect
 *    time (GET /me/accounts) and store that instead.
 *  - social_accounts.provider_user_id should hold the Page id.
 */
class FacebookPlatform extends AbstractSocialPlatform implements PublishesImages, PublishesVideos, PublishesText
{
    public function name(): string
    {
        return 'facebook';
    }

    public function publishImage(StoryImagePrompt $imagePrompt): SocialPostResult
    {
        try {
            $caption = trim(collect([$imagePrompt->pinterest_title, $imagePrompt->pinterest_description])->filter()->implode("\n\n"));

            $response = $this->http()->post("{$this->baseUrl()}/photos", [
                'url' => $imagePrompt->image_generated_url,
                'caption' => $caption,
                'link' => $imagePrompt->pinterest_link,
            ]);

            if ($response->failed()) {
                throw new RuntimeException("Facebook photo post failed: {$response->body()}");
            }

            $postId = $response->json('post_id') ?? $response->json('id');
            $this->recordPost(['story_image_prompt_id' => $imagePrompt->id, 'status' => 'posted', 'external_post_id' => $postId]);
            return SocialPostResult::success($postId);
        } catch (\Throwable $e) {
            $this->log()->error('FacebookPlatform: publishImage failed', ['error' => $e->getMessage()]);
            $this->recordPost(['story_image_prompt_id' => $imagePrompt->id, 'status' => 'failed', 'error' => $e->getMessage()]);
            return SocialPostResult::failure($e->getMessage());
        }
    }

    public function publishVideo(Video $video): SocialPostResult
    {
        try {
            $response = $this->http()->post("{$this->baseUrl()}/videos", [
                'file_url' => $video->video_url,
                'description' => $video->caption ?? '',
            ]);

            if ($response->failed()) {
                throw new RuntimeException("Facebook video post failed: {$response->body()}");
            }

            $videoId = $response->json('id');
            $this->recordPost(['video_id' => $video->id, 'status' => 'posted', 'external_post_id' => $videoId]);
            return SocialPostResult::success($videoId);
        } catch (\Throwable $e) {
            $this->log()->error('FacebookPlatform: publishVideo failed', ['video_id' => $video->id, 'error' => $e->getMessage()]);
            $this->recordPost(['video_id' => $video->id, 'status' => 'failed', 'error' => $e->getMessage()]);
            return SocialPostResult::failure($e->getMessage());
        }
    }

    public function publishText(string $text, ?string $linkUrl = null): SocialPostResult
    {
        try {
            $response = $this->http()->post("{$this->baseUrl()}/feed", array_filter([
                'message' => $text,
                'link' => $linkUrl,
            ]));

            if ($response->failed()) {
                throw new RuntimeException("Facebook feed post failed: {$response->body()}");
            }

            return SocialPostResult::success($response->json('id'));
        } catch (\Throwable $e) {
            $this->log()->error('FacebookPlatform: publishText failed', ['error' => $e->getMessage()]);
            return SocialPostResult::failure($e->getMessage());
        }
    }

    protected function baseUrl(): string
    {
        $version = config('services.facebook.graph_api_version', 'v21.0');
        $pageId = $this->account->provider_user_id;

        return "https://graph.facebook.com/{$version}/{$pageId}";
    }

    protected function http()
    {
        return Http::withToken($this->account->access_token)->timeout(30);
    }
}
