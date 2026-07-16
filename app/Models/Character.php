<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * img_url doubles as the character's generated reference/face image once
 * ImageGeneratorService::generateCharacterImage() has run for it — that
 * image is then fed back into Gemini as a reference when generating any
 * story scene image that includes this character.
 *
 * story_id records which episode a character was first introduced in — it
 * is NOT an ownership link for series characters. For any character whose
 * story is part of a series, series_id is also set, and that's the scope
 * used to find/reuse the character across every other episode (see
 * Story::knownCharacters()). Standalone (non-series) stories leave
 * series_id null and characters stay scoped to that one story.
 */
class Character extends Model
{
    use HasFactory;

    protected $fillable = [
        'story_id',
        'series_id',
        'name',
        'img_url',
        'img_url_quality',
        'image_prompt',
        'generated_at',
        'last_generation_error',
        'generation_attempts',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(StorySeries::class, 'series_id');
    }

    /** Characters that have a portrait prompt queued but no generated image yet. */
    public function scopeAwaitingPortrait($query)
    {
        return $query->whereNotNull('image_prompt')->whereNull('img_url');
    }
}
