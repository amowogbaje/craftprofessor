<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Collection;
class StoryImagePrompt extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';

    /**
     * Generated images go straight to "published" (feed-visible, eligible
     * for Pinterest posting) rather than sitting in "draft" waiting for a
     * manual publish step — the DB column still defaults to 'draft' for
     * any raw/non-Eloquent insert, but every real creation path (see
     * ImageGeneratorService::generatePromptsForStory()) sets this
     * explicitly, and this is a safety net for any future one that forgets to.
     */
    protected $attributes = [
        'status' => self::STATUS_PUBLISHED,
    ];

    protected $fillable = [
        'user_id',
        'story_id',
        'prompt',
        'narration',
        'dialogue_lines',
        'narration_audio_url',
        'narration_audio_seconds',
        'last_narration_error',
        'scene_number',
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
        'main_environment_ids',
        'main_prop_ids',
        'pinterest_title',
        'pinterest_description',
        'pinterest_link',
        'posted_to_pinterest',
        'pinterest_posted_at',
        'pinterest_pin_id',
        'last_pinterest_error',
    ];

    protected $casts = [
        'dialogue_lines' => 'array',
        'main_character_ids' => 'array',
        'main_environment_ids' => 'array',
        'main_prop_ids' => 'array',
        'narration_audio_seconds' => 'float',
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

    public function video(): HasOneThrough
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

    /**
     * True only once this scene has a video that actually finished (has a
     * video_url) — NOT just "a VideoPrompt row exists." VideoGeneratorService
     * creates the Video row up front (before the provider call) so it has
     * something to update on success/failure, so an existing Video row with
     * a null video_url means a *failed* attempt, not a completed one. Every
     * "is this already done?" check in this app should use this method
     * rather than `videoPrompt()->exists()` / `video()->exists()` directly —
     * see clearVideoAttempt() below for why that distinction used to cause a
     * stuck state.
     */
    public function hasCompletedVideo(): bool
    {
        return $this->video()->whereNotNull('video_url')->exists();
    }

    /**
     * Deletes any unfinished/failed video generation attempt for this scene
     * (a VideoPrompt row, and/or a Video row under it, that never got a
     * video_url) so a fresh attempt can start clean.
     *
     * Why this exists: VideoPromptService and VideoGeneratorService both
     * create their row *before* calling out to the AI provider (so there's
     * something to attach the eventual result — or error — to), and neither
     * deletes that row if the provider call throws. That's fine for
     * historical/debugging purposes, but it used to mean
     * VideoController::store()'s "has a video already been requested?"
     * guard — which only checked whether a VideoPrompt row existed at all —
     * stayed permanently true after any failure, even though nothing had
     * actually succeeded. The user's only way out was knowing to call the
     * separate /regenerate endpoint instead of just trying again.
     *
     * Passing `true` also clears a *completed* video (regenerate's
     * behavior); the default only clears stale/failed attempts, leaving a
     * real completed video alone.
     */
    public function clearVideoAttempt(bool $evenIfCompleted = false): void
    {
        $videoPrompt = $this->videoPrompt;

        if (!$videoPrompt) {
            return;
        }

        if (!$evenIfCompleted && $this->hasCompletedVideo()) {
            return;
        }

        Video::where('video_prompt_id', $videoPrompt->id)->delete();
        $videoPrompt->delete();
        $this->unsetRelation('videoPrompt');
        $this->unsetRelation('video');
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

    /** Narration text exists but hasn't been turned into audio yet. */
    public function scopeAwaitingNarrationAudio($query)
    {
        return $query->whereNotNull('narration')
            ->whereNull('narration_audio_url')
            ->where('generation_attempts', '<', config('images.max_generation_attempts', 5));
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

    /** True when this scene has actual character-to-character dialogue lines rather than plain narration. */
    public function hasDialogue(): bool
    {
        return !empty($this->dialogue_lines);
    }

    /** Story reading/playback order — scenes are generated in story order. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('scene_number');
    }

    public function mainCharacters(): Collection
    {
        $ids = $this->main_character_ids ?? [];

        return empty($ids) ? collect() : Character::whereIn('id', $ids)->get();
    }

    public function mainEnvironments(): Collection
    {
        $ids = $this->main_environment_ids ?? [];

        return empty($ids) ? collect() : Environment::whereIn('id', $ids)->get();
    }

    public function mainProps(): Collection
    {
        $ids = $this->main_prop_ids ?? [];

        return empty($ids) ? collect() : Prop::whereIn('id', $ids)->get();
    }

    /** Every reference-image asset (characters + environments + props) attached to this prompt. */
    public function allReferenceAssets(): Collection
    {
        return $this->mainCharacters()
            ->concat($this->mainEnvironments())
            ->concat($this->mainProps());
    }

    public function characters()
    {
        return Character::whereIn('id', $this->main_character_ids ?? []);
    }
}
