<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class StoryImagePrompt extends Model
{
    use HasFactory;

    protected $fillable = [
        'story_id',
        'prompt',
        'image_generated_url',
        'generated_at',
        'last_generation_error',
        'generation_attempts',
        'main_character_ids',
        'pinterest_title',
        'pinterest_description',
        'pinterest_link',
        'posted_to_pinterest',
        'pinterest_posted_at',
        'pinterest_pin_id',
        'last_pinterest_error',
    ];

    protected $casts = [
        'main_character_ids' => 'array',
        'generated_at' => 'datetime',
        'posted_to_pinterest' => 'boolean',
        'pinterest_posted_at' => 'datetime',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** Scheduler 3 target: prompt exists but no image yet. */
    public function scopeAwaitingImage($query)
    {
        return $query->whereNull('image_generated_url');
    }

    /** Scheduler 4 target: image is ready but not yet posted to Pinterest. */
    public function scopeAwaitingPinterestPost($query)
    {
        return $query->whereNotNull('image_generated_url')->where('posted_to_pinterest', false);
    }

    public function mainCharacters(): Collection
    {
        $ids = $this->main_character_ids ?? [];

        return empty($ids) ? collect() : Character::whereIn('id', $ids)->get();
    }

    public function characters()
    {
        // Assuming you store IDs in a JSON column called 'main_character_ids'
        // This allows the query builder to look up characters by these IDs
        return Character::whereIn('id', $this->main_character_ids ?? []);
    }
}
