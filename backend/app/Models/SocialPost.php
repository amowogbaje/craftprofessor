<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPost extends Model
{
    protected $fillable = [
        'user_id',
        'story_image_prompt_id',
        'video_id',
        'story_video_id',
        'platform',
        'pinterest_board_id',
        'cause_broadcast_id',
        'status',
        'external_post_id',
        'error',
        'posted_at',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storyImagePrompt(): BelongsTo
    {
        return $this->belongsTo(StoryImagePrompt::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function storyVideo(): BelongsTo
    {
        return $this->belongsTo(StoryVideo::class);
    }

    public function pinterestBoard(): BelongsTo
    {
        return $this->belongsTo(PinterestBoard::class);
    }

    public function causeBroadcast(): BelongsTo
    {
        return $this->belongsTo(CauseBroadcast::class);
    }

    public function markPosted(string $externalPostId): void
    {
        $this->update(['status' => 'posted', 'external_post_id' => $externalPostId, 'posted_at' => now(), 'error' => null]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'error' => $error]);
    }
}
