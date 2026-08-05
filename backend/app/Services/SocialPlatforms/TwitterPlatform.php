<?php

namespace App\Services\SocialPlatforms;

use App\Models\Video;
use App\Services\SocialPlatforms\Contracts\PublishesImages;
use App\Services\SocialPlatforms\Contracts\PublishesRawImage;
use App\Services\SocialPlatforms\Contracts\PublishesRawVideo;
use App\Services\SocialPlatforms\Contracts\PublishesVideos;
use App\Services\SocialPlatforms\Contracts\PublishesText;
use App\Services\SocialPlatforms\DTO\SocialPostResult;
use App\Models\StoryImagePrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ⚠ SETUP REQUIRED before this can post for real:
 *  - A Twitter/X Developer App on a paid tier (media upload + posting on
 *    behalf of users requires Basic tier or above as of this writing).
 *  - Two separate credential sets, because Twitter/X has never fully
 *    unified this: OAuth 2.0 (PKCE, user context) for creating the tweet
 *    itself via API v2, AND OAuth 1.0a user-context signing for the media
 *    upload endpoint, which is still v1.1-only. Both live in
 *    services.twitter.* — see config/services.php.
 *  - Verify the chunked video upload flow (INIT/APPEND/FINALIZE) against
 *    Twitter's current docs before relying on it; the shape below is
 *    correct as of this writing but this endpoint has changed before.
 */
class TwitterPlatform extends AbstractSocialPlatform implements PublishesImages, PublishesVideos, PublishesText, PublishesRawImage, PublishesRawVideo
{
    private const MEDIA_UPLOAD_URL = 'https://upload.twitter.com/1.1/media/upload.json';
    private const TWEETS_URL = 'https://api.twitter.com/2/tweets';

    public function name(): string
    {
        return 'twitter';
    }

    public function publishImage(StoryImagePrompt $imagePrompt): SocialPostResult
    {
        $result = $this->publishRawImage(
            (string) $imagePrompt->image_generated_url,
            $imagePrompt->caption ?? $imagePrompt->pinterest_title ?? '',
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
            $imageBytes = Http::timeout(30)->get($imageUrl)->body();
            $mediaId = $this->uploadMediaSimple($imageBytes, 'image/jpeg');

            $text = trim(collect([$title, $details])->filter()->implode("\n\n")) ?: $title;
            $tweetId = $this->createTweet($linkUrl ? "{$text}\n\n{$linkUrl}" : $text, [$mediaId]);

            return SocialPostResult::success($tweetId);
        } catch (\Throwable $e) {
            $this->log()->error('TwitterPlatform: publishRawImage failed', ['error' => $e->getMessage()]);
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
            $mediaId = $this->uploadMediaChunked($videoUrl, 'video/mp4');
            $tweetId = $this->createTweet($linkUrl ? "{$caption}\n\n{$linkUrl}" : $caption, [$mediaId]);

            return SocialPostResult::success($tweetId);
        } catch (\Throwable $e) {
            $this->log()->error('TwitterPlatform: publishRawVideo failed', ['error' => $e->getMessage()]);
            return SocialPostResult::failure($e->getMessage());
        }
    }

    public function publishText(string $text, ?string $linkUrl = null): SocialPostResult
    {
        try {
            $tweetId = $this->createTweet($linkUrl ? "{$text}\n\n{$linkUrl}" : $text);
            return SocialPostResult::success($tweetId);
        } catch (\Throwable $e) {
            $this->log()->error('TwitterPlatform: publishText failed', ['error' => $e->getMessage()]);
            return SocialPostResult::failure($e->getMessage());
        }
    }

    protected function createTweet(string $text, array $mediaIds = []): string
    {
        $payload = ['text' => $text];
        if (!empty($mediaIds)) {
            $payload['media'] = ['media_ids' => $mediaIds];
        }

        // API v2 posting uses the user's own OAuth2 access token (Bearer).
        $response = Http::withToken($this->account->access_token)
            ->timeout(30)
            ->post(self::TWEETS_URL, $payload);

        if ($response->failed()) {
            throw new RuntimeException("Tweet creation failed: {$response->body()}");
        }

        $id = $response->json('data.id');
        if (!$id) {
            throw new RuntimeException('Twitter did not return a tweet id.');
        }

        return $id;
    }

    protected function uploadMediaSimple(string $bytes, string $mimeType): string
    {
        $response = Http::withHeaders($this->oauth1Header('POST', self::MEDIA_UPLOAD_URL))
            ->attach('media', $bytes, 'upload')
            ->timeout(60)
            ->post(self::MEDIA_UPLOAD_URL);

        if ($response->failed()) {
            throw new RuntimeException("Twitter media upload failed: {$response->body()}");
        }

        return (string) $response->json('media_id_string');
    }

    /** INIT -> APPEND (base64 chunks) -> FINALIZE, per Twitter's chunked upload flow. */
    protected function uploadMediaChunked(string $videoUrl, string $mimeType): string
    {
        $bytes = Http::timeout(60)->get($videoUrl)->body();
        $totalBytes = strlen($bytes);

        $init = Http::withHeaders($this->oauth1Header('POST', self::MEDIA_UPLOAD_URL))
            ->asForm()
            ->post(self::MEDIA_UPLOAD_URL, [
                'command' => 'INIT',
                'total_bytes' => $totalBytes,
                'media_type' => $mimeType,
                'media_category' => 'tweet_video',
            ]);

        if ($init->failed()) {
            throw new RuntimeException("Twitter chunked upload INIT failed: {$init->body()}");
        }

        $mediaId = $init->json('media_id_string');
        $chunkSize = 4 * 1024 * 1024; // 4MB per Twitter's guidance

        $chunks = str_split($bytes, $chunkSize);

        foreach ($chunks as $index => $segment) {
            $append = Http::withHeaders($this->oauth1Header('POST', self::MEDIA_UPLOAD_URL))
                ->attach('media', $segment, 'chunk')
                ->post(self::MEDIA_UPLOAD_URL, [
                    'command' => 'APPEND',
                    'media_id' => $mediaId,
                    'segment_index' => $index,
                ]);

            if ($append->failed()) {
                throw new RuntimeException("Twitter chunked upload APPEND (segment {$index}) failed: {$append->body()}");
            }
        }

        $finalize = Http::withHeaders($this->oauth1Header('POST', self::MEDIA_UPLOAD_URL))
            ->asForm()
            ->post(self::MEDIA_UPLOAD_URL, ['command' => 'FINALIZE', 'media_id' => $mediaId]);

        if ($finalize->failed()) {
            throw new RuntimeException("Twitter chunked upload FINALIZE failed: {$finalize->body()}");
        }

        return $mediaId;
    }

    /** Minimal OAuth 1.0a user-context Authorization header for the v1.1 media endpoint. */
    protected function oauth1Header(string $method, string $url): array
    {
        $consumerKey = config('services.twitter.consumer_key');
        $consumerSecret = config('services.twitter.consumer_secret');
        $token = config('services.twitter.access_token');
        $tokenSecret = config('services.twitter.access_token_secret');

        $params = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => Str::random(32),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $token,
            'oauth_version' => '1.0',
        ];

        $baseString = $method . '&' . rawurlencode($url) . '&' . rawurlencode(
            collect($params)->sortKeys()->map(fn ($v, $k) => "{$k}={$v}")->implode('&')
        );
        $signingKey = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);
        $params['oauth_signature'] = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));

        $header = 'OAuth ' . collect($params)->map(fn ($v, $k) => $k . '="' . rawurlencode($v) . '"')->implode(', ');

        return ['Authorization' => $header];
    }
}
