<?php

namespace App\Services;

use App\Models\StoryImagePrompt;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Carbon;

/**
 * Enforces the daily/monthly generation limits a user sets on their
 * PublishSetting — independent of (and checked before) their coin balance.
 * A limit of 0/null on the monthly fields means "no monthly ceiling".
 */
class UsageLimitService
{
    public function canGenerateImage(User $user): bool
    {
        $settings = $user->publishSetting;
        if (!$settings) {
            return true;
        }

        if ($this->imagesGeneratedSince($user, Carbon::today()) >= $settings->daily_image_limit) {
            return false;
        }

        if ($settings->monthly_image_limit && $this->imagesGeneratedSince($user, Carbon::now()->startOfMonth()) >= $settings->monthly_image_limit) {
            return false;
        }

        return true;
    }

    public function canGenerateVideo(User $user): bool
    {
        $settings = $user->publishSetting;
        if (!$settings) {
            return true;
        }

        if ($this->videosGeneratedSince($user, Carbon::today()) >= $settings->daily_video_limit) {
            return false;
        }

        if ($settings->monthly_video_limit && $this->videosGeneratedSince($user, Carbon::now()->startOfMonth()) >= $settings->monthly_video_limit) {
            return false;
        }

        return true;
    }

    protected function imagesGeneratedSince(User $user, Carbon $since): int
    {
        return StoryImagePrompt::where('user_id', $user->id)
            ->whereNotNull('image_generated_url')
            ->where('generated_at', '>=', $since)
            ->count();
    }

    protected function videosGeneratedSince(User $user, Carbon $since): int
    {
        return Video::where('user_id', $user->id)
            ->whereNotNull('video_url')
            ->where('generated_at', '>=', $since)
            ->count();
    }
}
