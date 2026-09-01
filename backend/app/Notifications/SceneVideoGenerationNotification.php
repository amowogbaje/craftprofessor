<?php

namespace App\Notifications;

use App\Models\StoryImagePrompt;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Fired by SceneVideoGenerationService after every attempt (success or
 * failure), regardless of whether that attempt happened synchronously in
 * an HTTP request or inside a queued job — so the user finds out either
 * way, not just when a queue worker happens to be watching.
 *
 * database channel only for now (shows up via GET /api/notifications) —
 * add 'mail'/'broadcast' to via() later if that's ever wanted, nothing
 * else about this class would need to change.
 */
class SceneVideoGenerationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public StoryImagePrompt $scene,
        public bool $succeeded,
        public ?Video $video = null,
        public ?string $errorMessage = null,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'kind' => 'scene_video_generation',
            'succeeded' => $this->succeeded,
            'story_image_prompt_id' => $this->scene->id,
            'story_id' => $this->scene->story_id,
            'scene_number' => $this->scene->scene_number,
            'video_id' => $this->video?->id,
            'video_url' => $this->video?->video_url,
            'error_message' => $this->errorMessage,
            'message' => $this->succeeded
                ? 'Video ready for scene ' . ($this->scene->scene_number ?? $this->scene->id) . '.'
                : 'Video generation failed for scene ' . ($this->scene->scene_number ?? $this->scene->id) . ($this->errorMessage ? ": {$this->errorMessage}" : '.'),
        ];
    }
}
