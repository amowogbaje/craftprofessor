<?php

namespace App\Services\SocialPlatforms;

use App\Models\StoryImagePrompt;
use App\Models\Video;
use App\Services\SocialPlatforms\Contracts\PublishesImages;
use App\Services\SocialPlatforms\Contracts\PublishesRawImage;
use App\Services\SocialPlatforms\Contracts\PublishesRawVideo;
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
class FacebookPlatform extends AbstractSocialPlatform implements PublishesImages, PublishesVideos, PublishesText, PublishesRawImage, PublishesRawVideo
{
    public function name(): string
    {
        return 'facebook';
    }

    public function publishImage(StoryImagePrompt $imagePrompt): SocialPostResult
    {
        $result = $this->publishRawImage(
            (string) $imagePrompt->image_generated_url,
            (string) $imagePrompt->pinterest_title,
            $imagePrompt->pinterest_description,
            $imagePrompt->pinterest_link,
        );

        $this->recordPost([
            'story_image_prompt_id' => $imagePrompt->id,
            'status' => $result->success ? 'posted' : 'failed',
            'external_post_id' => $result->externalPostId,
            'error' => $result->error,
        ]);

        return $result;
    }

    public function publishRawImage(string $imageUrl, string $title, ?string $details = null, ?string $linkUrl = null): SocialPostResult
    {
        try {
            $caption = trim(collect([$title, $details])->filter()->implode("\n\n"));

            $response = $this->http()->post("{$this->baseUrl()}/photos", array_filter([
                'url' => $imageUrl,
                'caption' => $caption,
                'link' => $linkUrl,
            ]));

            if ($response->failed()) {
                throw new RuntimeException("Facebook photo post failed: {$response->body()}");
            }

            return SocialPostResult::success($response->json('post_id') ?? $response->json('id'));
        } catch (\Throwable $e) {
            $this->log()->error('FacebookPlatform: publishRawImage failed', ['error' => $e->getMessage()]);
            return SocialPostResult::failure($e->getMessage());
        }
    }

    public function publishVideo(Video $video): SocialPostResult
    {
        $result = $this->publishRawVideo((string) $video->video_url, $video->caption ?? '');

        $this->recordPost([
            'video_id' => $video->id,
            'status' => $result->success ? 'posted' : 'failed',
            'external_post_id' => $result->externalPostId,
            'error' => $result->error,
        ]);

        return $result;
    }

    public function publishRawVideo(string $videoUrl, string $caption, ?string $linkUrl = null): SocialPostResult
    {
        try {
            $response = $this->http()->post("{$this->baseUrl()}/videos", [
                'file_url' => $videoUrl,
                'description' => $linkUrl ? "{$caption}\n\n{$linkUrl}" : $caption,
            ]);

            if ($response->failed()) {
                throw new RuntimeException("Facebook video post failed: {$response->body()}");
            }

            return SocialPostResult::success($response->json('id'));
        } catch (\Throwable $e) {
            $this->log()->error('FacebookPlatform: publishRawVideo failed', ['error' => $e->getMessage()]);
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
