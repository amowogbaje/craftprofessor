<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryVideo extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'story_id',
        'user_id',
        'video_url',
        'duration_seconds',
        'scene_count',
        'status',
        'last_generation_error',
        'generation_attempts',
        'generated_at',
        'posted_to_pinterest',
        'pinterest_posted_at',
        'pinterest_pin_id',
        'last_pinterest_error',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'duration_seconds' => 'float',
        'posted_to_pinterest' => 'boolean',
        'pinterest_posted_at' => 'datetime',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
