<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Story extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'series_id',
        'episode_number',
        'title',
        'slug',
        'source',
        'published_at',
        'story_link',
        'story_text',
        'user_supplied_text',
        'prompt_generated',
        'fetch_attempts',
        'last_fetch_error',
    ];

    protected $casts = [
        'prompt_generated' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Keep user_id in sync with the owning series so every story is
        // always directly queryable/scopable by owner, series or not.
        static::saving(function (Story $story) {
            if ($story->series_id && $story->isDirty('series_id') && !$story->isDirty('user_id')) {
                $story->user_id = $story->series?->user_id ?? $story->user_id;
            }
        });

        static::creating(function (Story $story) {
            if (!$story->slug) {
                $story->slug = static::uniqueSlugFor($story->title ?: 'story');
            }
        });
    }

    public static function uniqueSlugFor(string $seed): string
    {
        $base = Str::slug($seed) ?: 'story';
        $slug = $base;
        $i = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-" . ++$i;
        }

        return $slug;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(StorySeries::class, 'series_id');
    }

    /** Characters first introduced in this specific story (origin, not full ownership). */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    /** Environments (settings/locations) first introduced in this specific story. */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /** Props (recurring significant objects) first introduced in this specific story. */
    public function props(): HasMany
    {
        return $this->hasMany(Prop::class);
    }

    public function imagePrompts(): HasMany
    {
        return $this->hasMany(StoryImagePrompt::class);
    }

    /** The final assembled scene-by-scene video with narration audio (see StoryVideoAssemblyService). */
    public function video(): HasOne
    {
        return $this->hasOne(StoryVideo::class);
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

    /** Same reuse-across-episodes logic as knownCharacters(), for environments. */
    public function knownEnvironments(): HasMany
    {
        return $this->isPartOfSeries()
            ? $this->series->environments()
            : $this->environments();
    }

    /** Same reuse-across-episodes logic as knownCharacters(), for props. */
    public function knownProps(): HasMany
    {
        return $this->isPartOfSeries()
            ? $this->series->props()
            : $this->props();
    }

    /** Scheduler 1 target: stories with no text yet (and no user-supplied text either). */
    public function scopeMissingText($query)
    {
        return $query->whereNull('story_text')->whereNull('user_supplied_text');
    }

    /** Scheduler 2 target: story text is ready but prompts haven't been generated. */
    public function scopeReadyForPrompts($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('story_text')->orWhereNotNull('user_supplied_text');
        })->where('prompt_generated', false);
    }

    /** The text to actually feed into prompt generation, whichever source it came from. */
    public function getEffectiveTextAttribute(): ?string
    {
        return $this->user_supplied_text ?: $this->story_text;
    }
}
