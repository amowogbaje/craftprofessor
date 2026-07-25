<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Video extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'user_id', 'video_prompt_id', 'story_image_prompt_id',
        'video_url', 'provider', 'duration_seconds',
        'status', 'scheduled_at', 'published_at',
        'coin_cost', 'generated_at', 'last_generation_error', 'generation_attempts',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videoPrompt(): BelongsTo
    {
        return $this->belongsTo(VideoPrompt::class);
    }

    public function imagePrompt(): BelongsTo
    {
        return $this->belongsTo(StoryImagePrompt::class, 'story_image_prompt_id');
    }

    public function scopeAwaitingGeneration($query)
    {
        return $query->whereNull('video_url');
    }
}
