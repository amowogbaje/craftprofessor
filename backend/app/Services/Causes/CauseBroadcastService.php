<?php

namespace App\Services\Causes;

use App\Models\CauseBroadcast;
use App\Models\CauseMedia;
use App\Models\CauseMember;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\SocialPlatforms\DTO\BroadcastContent;
use App\Services\SocialPlatforms\SocialContentRouter;
use App\Services\SocialPlatforms\SocialPlatformManager;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CauseBroadcastService
{
    public function __construct(
        protected SocialPlatformManager $platforms,
        protected SocialContentRouter $router,
    ) {
    }

    /**
     * Schedules `media` to post through `member`'s `provider` account.
     * `scheduledAtLocal` is interpreted in `timezone` (the scheduling
     * user's own timezone, per "post it at scheduled time in user
     * timezone") and converted to UTC for storage.
     */
    public function schedule(
        CauseMedia $media,
        CauseMember $member,
        string $provider,
        string $scheduledAtLocal,
        string $timezone,
        User $scheduledBy,
    ): CauseBroadcast {
        if (!$member->isJoined()) {
            throw new RuntimeException('This member has not joined the cause.');
        }

        $account = SocialAccount::where('user_id', $member->user_id)->where('provider', $provider)->first();

        if (!$account) {
            throw new RuntimeException("This member has no connected {$provider} account.");
        }

        $scheduledAtUtc = Carbon::parse($scheduledAtLocal, $timezone)->utc();

        $maxAhead = now()->addDays((int) config('causes.max_schedule_days_ahead', 90));
        if ($scheduledAtUtc->gt($maxAhead)) {
            throw new RuntimeException('Scheduled time is too far in the future.');
        }

        return CauseBroadcast::create([
            'cause_id' => $media->cause_id,
            'cause_media_id' => $media->id,
            'user_id' => $member->user_id,
            'provider' => $provider,
            'scheduled_by' => $scheduledBy->id,
            'scheduled_at' => $scheduledAtUtc,
            'timezone' => $timezone,
            'status' => 'scheduled',
        ]);
    }

    /** @return Collection<int, CauseBroadcast> every due broadcast this call processed. */
    public function publishDue(int $limit = 50): Collection
    {
        $due = CauseBroadcast::due()->with(['media', 'cause', 'user'])->oldest('scheduled_at')->limit($limit)->get();

        return $due->map(fn (CauseBroadcast $broadcast) => $this->publish($broadcast));
    }

    public function publish(CauseBroadcast $broadcast): CauseBroadcast
    {
        $member = CauseMember::where('cause_id', $broadcast->cause_id)->where('user_id', $broadcast->user_id)->first();

        if (!$member || !$member->isJoined()) {
            $broadcast->markSkipped('Member is no longer part of this cause.');
            return $broadcast;
        }

        try {
            $platform = $this->platforms->forUser($broadcast->user_id, $broadcast->provider);
        } catch (\Throwable $e) {
            $broadcast->markFailed("No connected {$broadcast->provider} account: {$e->getMessage()}");
            return $broadcast;
        }

        $media = $broadcast->media;
        $content = new BroadcastContent(
            type: $media->type,
            title: $media->title,
            details: $media->details,
            mediaUrl: $media->url,
            linkUrl: $media->link_url,
        );

        try {
            $result = $this->router->publish($platform, $content);

            $socialPost = SocialPost::create([
                'user_id' => $broadcast->user_id,
                'platform' => $broadcast->provider,
                'cause_broadcast_id' => $broadcast->id,
                'status' => $result->success ? 'posted' : 'failed',
                'external_post_id' => $result->externalPostId,
                'error' => $result->error,
                'posted_at' => $result->success ? now() : null,
            ]);

            if ($result->success) {
                $broadcast->markPosted($socialPost->id, $result->externalPostId);
            } else {
                $broadcast->markFailed($result->error ?? 'Unknown failure.');
            }
        } catch (\Throwable $e) {
            Log::error('CauseBroadcastService: publish failed', [
                'cause_broadcast_id' => $broadcast->id,
                'error' => $e->getMessage(),
            ]);
            $broadcast->markFailed($e->getMessage());
        }

        return $broadcast->fresh();
    }
}
