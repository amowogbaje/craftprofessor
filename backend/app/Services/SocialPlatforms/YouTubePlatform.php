<?php

namespace App\Services\SocialPlatforms;

use App\Models\Video;
use App\Services\SocialPlatforms\Contracts\PublishesRawVideo;
use App\Services\SocialPlatforms\Contracts\PublishesVideos;
use App\Services\SocialPlatforms\DTO\SocialPostResult;
use Illuminate\Support\Facades\Http;
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
