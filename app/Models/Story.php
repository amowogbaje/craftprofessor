<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Story extends Model
{
    use HasFactory;

    protected $fillable = [
        'series_id',
        'episode_number',
        'medium_link',
        'story_text',
        'prompt_generated',
        'fetch_attempts',
        'last_fetch_error',
    ];

    protected $casts = [
        'prompt_generated' => 'boolean',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(StorySeries::class, 'series_id');
    }

    /** Characters first introduced in this specific story (origin, not full ownership). */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function imagePrompts(): HasMany
    {
        return $this->hasMany(StoryImagePrompt::class);
    }

    public function isPartOfSeries(): bool
    {
        return !is_null($this->series_id);
    }

    /**
     * The correct character pool to check against before creating a new
     * character: if this story belongs to a series, that's every character
     * anywhere in the series (so episode 2 reuses episode 1's characters
     * instead of recreating them); otherwise it's just this story's own
     * characters.
     */
    public function knownCharacters(): HasMany
    {
        return $this->isPartOfSeries()
            ? $this->series->characters()
            : $this->characters();
    }

    /** Scheduler 1 target: stories with no text yet. */
    public function scopeMissingText($query)
    {
        return $query->whereNull('story_text');
    }

    /** Scheduler 2 target: story text is ready but prompts haven't been generated. */
    public function scopeReadyForPrompts($query)
    {
        return $query->whereNotNull('story_text')->where('prompt_generated', false);
    }
}
