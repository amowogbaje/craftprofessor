<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'social_media',
        'target_url',
        'story_id',
        'story_image_prompt_id',
        'ip_address',
        'user_agent',
        'referrer',
        'clicked_at',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (LinkClick $click) {
            $click->clicked_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function storyImagePrompt(): BelongsTo
    {
        return $this->belongsTo(StoryImagePrompt::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeBetween($query, $from, $to)
    {
        return $query->whereBetween('clicked_at', [$from, $to]);
    }
}
