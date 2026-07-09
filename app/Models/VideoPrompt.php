<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VideoPrompt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'story_image_prompt_id', 'prompt', 'coin_cost',
        'generated_at', 'last_generation_error', 'generation_attempts',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function imagePrompt(): BelongsTo
    {
        return $this->belongsTo(StoryImagePrompt::class, 'story_image_prompt_id');
    }

    public function video(): HasOne
    {
        return $this->hasOne(Video::class);
    }

    /** Video prompts that exist but haven't produced a video yet. */
    public function scopeAwaitingVideo($query)
    {
        return $query->whereDoesntHave('video');
    }
}
