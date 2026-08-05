<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CauseBroadcast extends Model
{
    protected $fillable = [
        'cause_id', 'cause_media_id', 'user_id', 'provider', 'scheduled_by',
        'scheduled_at', 'timezone', 'status', 'social_post_id', 'error', 'posted_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(CauseMedia::class, 'cause_media_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')->where('scheduled_at', '<=', now());
    }

    public function markPosted(?int $socialPostId, ?string $externalPostId = null): void
    {
        $this->update([
            'status' => 'posted',
            'social_post_id' => $socialPostId,
            'posted_at' => now(),
            'error' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'error' => $error]);
    }

    public function markSkipped(string $reason): void
    {
        $this->update(['status' => 'skipped', 'error' => $reason]);
    }
}
