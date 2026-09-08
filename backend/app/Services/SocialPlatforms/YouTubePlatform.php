<?php

namespace App\Services\SocialPlatforms;

use App\Models\SocialAccount;
use App\Models\StoryVideo;
use App\Models\Video;
use App\Services\SocialPlatforms\Contracts\PublishesRawVideo;
use App\Services\SocialPlatforms\Contracts\PublishesVideos;
use App\Services\SocialPlatforms\DTO\SocialPostResult;
use App\Services\YouTubeShortsExportService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ⚠ SETUP REQUIRED before this can post for real:
 *  - A Google Cloud project with the YouTube Data API v3 enabled, OAuth
 *    consent screen, and the account connected with the
 *    youtube.upload scope.
 *  - YouTube API has a daily quota (10,000 units/day by default; one
 *    upload costs 1,600 units) — fine for a handful of videos/day, but
 *    worth knowing before scheduling this alongside other automation.
 *  - The single-PUT upload below works for typical short-form video sizes;
 *    for anything large/unreliable-network, switch to actually chunked
 *    resumable PUTs (send Content-Range per chunk) rather than one big PUT.
 */
class YouTubePlatform extends AbstractSocialPlatform implements PublishesVideos, PublishesRawVideo
{
    public function name(): string
    {
        return 'youtube';
    }

    /**
     * Refresh the account's Google access token first if it's expired or
     * about to expire, same idea as PinterestService::ensureFreshToken()
     * but kept here rather than in AbstractSocialPlatform — see that
     * class's doc-comment on why refresh stays provider-specific. This is
     * what makes `youtube:post-daily-short` safe to run once a day without
     * a separate proactive refresh command: Google access tokens only live
     * ~1 hour, so by the time tomorrow's job runs the stored token is
     * always stale and needs refreshing right before use.
     */
    public static function forAccount(SocialAccount $account): static
    {
        return new static(self::ensureFreshToken($account));
    }

    protected static function ensureFreshToken(SocialAccount $account, int $bufferMinutes = 5): SocialAccount
    {
        if (!$account->token_expires_at || !$account->refresh_token) {
            return $account; // legacy row or no refresh token — nothing we can do
        }

        if ($account->token_expires_at->gt(now()->addMinutes($bufferMinutes))) {
            return $account; // still valid for a while
        }

        try {
            $response = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.youtube.client_id'),
                'client_secret' => config('services.youtube.client_secret'),
                'refresh_token' => $account->refresh_token,
                'grant_type' => 'refresh_token',
            ]);

            if ($response->failed()) {
                throw new RuntimeException("Google token refresh failed: {$response->body()}");
            }

            $token = $response->json();

            $account->update([
                'access_token' => $token['access_token'],
                // Google only returns a new refresh_token in rare cases
                // (e.g. re-consent) — keep the existing one otherwise.
                'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
                'token_expires_at' => isset($token['expires_in'])
                    ? now()->addSeconds($token['expires_in'])
                    : $account->token_expires_at,
            ]);

            Log::channel('youtube')->info('YouTubePlatform: access token refreshed automatically', [
                'user_id' => $account->user_id,
                'new_expires_at' => $account->token_expires_at,
            ]);
        } catch (\Throwable $e) {
            Log::channel('youtube')->error('YouTubePlatform: automatic token refresh failed', [
                'user_id' => $account->user_id,
                'error' => $e->getMessage(),
            ]);
            // Return the stale account — the upload call below will fail
            // with a clear 401 rather than us throwing here mid-refresh.
        }

        return $account;
    }

    /**
     * Publishes a story's assembled video as a YouTube Short. Trims to the
     * current Shorts length limit first via YouTubeShortsExportService (a
     * no-op re-encode if the video already qualifies) — see that class and
     * YouTube.md for why 180s is the cutoff.
     */
    public function publishShort(StoryVideo $storyVideo): SocialPostResult
    {
        try {
            $shortsUrl = app(YouTubeShortsExportService::class)->export($storyVideo);

            $firstScene = $storyVideo->story->imagePrompts()->ordered()->first();
            $title = $firstScene?->pinterest_title ?: ($storyVideo->story->title ?: 'A story');
            $description = trim(($firstScene?->pinterest_description ?: '') . "\n\n#Shorts");
            $link = $firstScene?->pinterest_link ?: $storyVideo->story->story_link;

            $videoId = $this->upload($shortsUrl, $title, $link ? "{$description}\n\n{$link}" : $description);

            $this->recordPost([
                'story_video_id' => $storyVideo->id,
                'status' => 'posted',
                'external_post_id' => $videoId,
            ]);

            return SocialPostResult::success($videoId);
        } catch (\Throwable $e) {
            $this->log()->error('YouTubePlatform: publishShort failed', ['error' => $e->getMessage()]);

            $this->recordPost([
                'story_video_id' => $storyVideo->id,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            return SocialPostResult::failure($e->getMessage());
        }
    }

    public function publishVideo(Video $video): SocialPostResult
    {
        $result = $this->publishRawVideo((string) $video->video_url, $video->caption ?? '', null, $video->title ?? 'Untitled');

        $this->recordPost([
            'video_id' => $video->id,
            'status' => $result->success ? 'posted' : 'failed',
            'external_post_id' => $result->externalPostId,
            'error' => $result->error,
        ]);

        return $result;
    }

    public function publishRawVideo(string $videoUrl, string $caption, ?string $linkUrl = null, string $title = 'Untitled'): SocialPostResult
    {
        try {
            $videoId = $this->upload($videoUrl, $title, $linkUrl ? "{$caption}\n\n{$linkUrl}" : $caption);
            return SocialPostResult::success($videoId);
        } catch (\Throwable $e) {
            $this->log()->error('YouTubePlatform: publishRawVideo failed', ['error' => $e->getMessage()]);
            return SocialPostResult::failure($e->getMessage());
        }
    }

    protected function upload(string $videoUrl, string $title, string $description): string
    {
        $metadata = [
            'snippet' => [
                'title' => $title,
                'description' => $description,
                'categoryId' => '24', // Entertainment
            ],
            'status' => [
                'privacyStatus' => 'public',
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        $init = Http::withToken($this->account->access_token)
            ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8'])
            ->timeout(30)
            ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', $metadata);

        if ($init->failed()) {
            throw new RuntimeException("YouTube resumable session init failed: {$init->body()}");
        }

        $uploadUrl = $init->header('Location');
        if (!$uploadUrl) {
            throw new RuntimeException('YouTube did not return a resumable upload URL.');
        }

        $videoBytes = Http::timeout(60)->get($videoUrl)->body();

        $upload = Http::withToken($this->account->access_token)
            ->withHeaders(['Content-Type' => 'video/mp4'])
            ->timeout(120)
            ->put($uploadUrl, $videoBytes);

        if ($upload->failed()) {
            throw new RuntimeException("YouTube video upload failed: {$upload->body()}");
        }

        $videoId = $upload->json('id');
        if (!$videoId) {
            throw new RuntimeException('YouTube did not return a video id.');
        }

        return $videoId;
    }
}
