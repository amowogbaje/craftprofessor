<?php

namespace App\Services\SocialPlatforms;

use App\Models\StoryImagePrompt;
use App\Services\SocialPlatforms\Contracts\PublishesImages;
use App\Services\SocialPlatforms\Contracts\PublishesRawImage;
use App\Services\SocialPlatforms\Contracts\PublishesRawVideo;
use App\Services\SocialPlatforms\Contracts\PublishesText;
use App\Services\SocialPlatforms\DTO\SocialPostResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts via LinkedIn's current REST API (api.linkedin.com/rest/*), which
 * superseded the older /v2/ugcPosts endpoint. Requires:
 *  - social_accounts.provider_user_id populated with the LinkedIn member's
 *    "sub" claim from OpenID Connect (used to build the author URN
 *    urn:li:person:{id}), set at connect-time.
 *  - the w_member_social (and openid/profile for the id) OAuth scopes.
 *
 * NOTE: LinkedIn's REST API is calendar-versioned via the LinkedIn-Version
 * header (services.linkedin.api_version) and that header needs bumping
 * roughly yearly as old versions age out — see LinkedIn's API changelog.
 */
class LinkedInPlatform extends AbstractSocialPlatform implements PublishesImages, PublishesText, PublishesRawImage, PublishesRawVideo
{
    public function name(): string
    {
        return 'linkedin';
    }

    public function publishImage(StoryImagePrompt $imagePrompt): SocialPostResult
    {
        $result = $this->publishRawImage(
            (string) $imagePrompt->image_generated_url,
            (string) $imagePrompt->pinterest_title,
            $imagePrompt->pinterest_description,
        );

        $this->recordPost([
            'story_image_prompt_id' => $imagePrompt->id,
            'status' => $result->success ? 'posted' : 'failed',
            'external_post_id' => $result->externalPostId,
            'error' => $result->error,
        ]);

        return $result;
    }

    /** Cause-media / any raw-URL image, decoupled from StoryImagePrompt. */
    public function publishRawImage(string $imageUrl, string $title, ?string $details = null, ?string $linkUrl = null): SocialPostResult
    {
        try {
            $assetUrn = $this->uploadImage($imageUrl);

            $text = trim(collect([$title, $details])->filter()->implode("\n\n"));

            $postId = $this->createPost($text, [
                'media' => array_filter([
                    'title' => $title,
                    'id' => $assetUrn,
                ]),
            ]);

            return SocialPostResult::success($postId);
        } catch (\Throwable $e) {
            $this->log()->error('LinkedInPlatform: publishRawImage failed', ['error' => $e->getMessage()]);

            return SocialPostResult::failure($e->getMessage());
        }
    }

    /**
     * Single-part video upload via LinkedIn's REST video-assets endpoint.
     * Suitable for short-form video (LinkedIn's own guidance caps
     * single-part uploads well under 200MB); for longer video, switch to
     * the multipart uploadInstructions LinkedIn returns from
     * initializeUpload instead of assuming index 0 covers the whole file.
     */
    public function publishRawVideo(string $videoUrl, string $caption, ?string $linkUrl = null): SocialPostResult
    {
        try {
            $assetUrn = $this->uploadVideo($videoUrl);

            $postId = $this->createPost($caption, [
                'content' => ['media' => ['id' => $assetUrn]],
            ]);

            return SocialPostResult::success($postId);
        } catch (\Throwable $e) {
            $this->log()->error('LinkedInPlatform: publishRawVideo failed', ['error' => $e->getMessage()]);

            return SocialPostResult::failure($e->getMessage());
        }
    }

    public function publishText(string $text, ?string $linkUrl = null): SocialPostResult
    {
        try {
            $article = $linkUrl ? ['source' => $linkUrl] : null;
            $postId = $this->createPost($text, $article ? ['article' => $article] : []);

            return SocialPostResult::success($postId);
        } catch (\Throwable $e) {
            $this->log()->error('LinkedInPlatform: publishText failed', ['error' => $e->getMessage()]);

            return SocialPostResult::failure($e->getMessage());
        }
    }

    protected function authorUrn(): string
    {
        if (!$this->account->provider_user_id) {
            throw new RuntimeException('Connected LinkedIn account is missing provider_user_id — reconnect the account.');
        }

        return "urn:li:person:{$this->account->provider_user_id}";
    }

    /** Registers + uploads an image, returns its LinkedIn asset URN. */
    protected function uploadImage(?string $imageUrl): string
    {
        if (!$imageUrl) {
            throw new RuntimeException('No image URL to upload.');
        }

        $init = $this->http()->post('https://api.linkedin.com/rest/images?action=initializeUpload', [
            'initializeUploadRequest' => ['owner' => $this->authorUrn()],
        ]);

        if ($init->failed()) {
            throw new RuntimeException("LinkedIn image upload init failed: {$init->body()}");
        }

        $uploadUrl = $init->json('value.uploadUrl');
        $imageUrn = $init->json('value.image');

        if (!$uploadUrl || !$imageUrn) {
            throw new RuntimeException('LinkedIn did not return an uploadUrl/image URN.');
        }

        $imageBytes = Http::timeout(30)->get($imageUrl)->body();

        $upload = Http::withToken($this->account->access_token)
            ->withBody($imageBytes, 'application/octet-stream')
            ->put($uploadUrl);

        if ($upload->failed()) {
            throw new RuntimeException("LinkedIn image binary upload failed: {$upload->body()}");
        }

        return $imageUrn;
    }

    /** Registers + uploads a video, returns its LinkedIn asset URN. */
    protected function uploadVideo(string $videoUrl): string
    {
        $videoBytes = Http::timeout(60)->get($videoUrl)->body();

        $init = $this->http()->post('https://api.linkedin.com/rest/videos?action=initializeUpload', [
            'initializeUploadRequest' => [
                'owner' => $this->authorUrn(),
                'fileSizeBytes' => strlen($videoBytes),
                'uploadCaptions' => false,
                'uploadThumbnail' => false,
            ],
        ]);

        if ($init->failed()) {
            throw new RuntimeException("LinkedIn video upload init failed: {$init->body()}");
        }

        $uploadUrl = $init->json('value.uploadInstructions.0.uploadUrl');
        $videoUrn = $init->json('value.video');

        if (!$uploadUrl || !$videoUrn) {
            throw new RuntimeException('LinkedIn did not return an uploadUrl/video URN.');
        }

        $upload = Http::withToken($this->account->access_token)
            ->withBody($videoBytes, 'application/octet-stream')
            ->timeout(120)
            ->put($uploadUrl);

        if ($upload->failed()) {
            throw new RuntimeException("LinkedIn video binary upload failed: {$upload->body()}");
        }

        $etag = $upload->header('ETag');

        $finalize = $this->http()->post('https://api.linkedin.com/rest/videos?action=finalizeUpload', [
            'finalizeUploadRequest' => [
                'video' => $videoUrn,
                'uploadToken' => '',
                'uploadedPartIds' => $etag ? [$etag] : [],
            ],
        ]);

        if ($finalize->failed()) {
            throw new RuntimeException("LinkedIn video finalize failed: {$finalize->body()}");
        }

        return $videoUrn;
    }

    /** @param array $content Either ['media' => ['id' => ..., 'title' => ...]] or ['article' => [...]] or []. */
    protected function createPost(string $text, array $content = []): string
    {
        $payload = array_filter([
            'author' => $this->authorUrn(),
            'commentary' => $text,
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ] + $content);

        $response = $this->http()->post('https://api.linkedin.com/rest/posts', $payload);

        if ($response->failed()) {
            throw new RuntimeException("LinkedIn post creation failed: {$response->body()}");
        }

        // LinkedIn returns the created post's URN in the x-restli-id header, not the body.
        $postId = $response->header('x-restli-id') ?: $response->json('id');

        if (!$postId) {
            throw new RuntimeException('LinkedIn did not return a post id.');
        }

        return $postId;
    }

    protected function http()
    {
        return Http::withToken($this->account->access_token)
            ->withHeaders([
                'LinkedIn-Version' => config('services.linkedin.api_version'),
                'X-Restli-Protocol-Version' => '2.0.0',
                'Content-Type' => 'application/json',
            ])
            ->timeout(30);
    }
}
