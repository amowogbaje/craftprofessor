<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class StoryImagePrompt extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'user_id',
        'story_id',
        'prompt',
        'image_generated_url',
        'image_generated_url_quality',
        'status',
        'scheduled_at',
        'published_at',
        'generated_at',
        'last_generation_error',
        'generation_attempts',
        'prompt_coin_cost',
        'image_coin_cost',
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
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'posted_to_pinterest' => 'boolean',
        'pinterest_posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Default the owner to the parent story's owner unless explicitly set.
        static::saving(function (StoryImagePrompt $prompt) {
            if (!$prompt->user_id && $prompt->story_id) {
                $prompt->user_id = $prompt->story?->user_id;
            }
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

    public function videoPrompt(): HasOne
    {
        return $this->hasOne(VideoPrompt::class);
    }

    public function video(): HasOne
    {
        return $this->hasOneThrough(
            Video::class,
            VideoPrompt::class,
            'story_image_prompt_id', // FK on video_prompts
            'video_prompt_id',       // FK on videos
            'id',
            'id'
        );
    }

    /** Every attempted post (any platform, any board) for this image. */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /**
     * Scheduler 3 target: prompt exists but no image yet, excluding ones
     * that have hard-failed too many times already (see Character's
     * scopeAwaitingPortrait for why the cap is needed).
     */
    public function scopeAwaitingImage($query)
    {
        return $query->whereNull('image_generated_url')
            ->where('generation_attempts', '<', config('images.max_generation_attempts', 5));
    }

    /** Scheduler 4 target: image is ready but not yet posted to Pinterest. */
    public function scopeAwaitingPinterestPost($query)
    {
        return $query->whereNotNull('image_generated_url')->where('status', self::STATUS_PUBLISHED)->where('posted_to_pinterest', false);
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    public function scopeDueForPublishing($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)->where('scheduled_at', '<=', now());
    }

    public function mainCharacters(): Collection
    {
        $ids = $this->main_character_ids ?? [];

        return empty($ids) ? collect() : Character::whereIn('id', $ids)->get();
    }

    public function characters()
    {
        return Character::whereIn('id', $this->main_character_ids ?? []);
    }
}
