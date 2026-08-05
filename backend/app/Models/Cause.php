<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Cause extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id', 'title', 'slug', 'description', 'goal', 'status',
        'creation_fee_amount', 'creation_fee_currency', 'payment_status',
        'payment_reference', 'payment_waived_reason', 'paid_at',
    ];

    protected $casts = [
        'creation_fee_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Cause $cause) {
            if (!$cause->slug) {
                $cause->slug = static::uniqueSlugFor($cause->title);
            }
        });
    }

    public static function uniqueSlugFor(string $title): string
    {
        $base = Str::slug($title) ?: 'cause';
        $slug = $base;
        $i = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-" . ++$i;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Accepts either the numeric id or the slug in a {cause} route segment. */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return static::where('id', $value)->orWhere('slug', $value)->first();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(CauseMedia::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(CauseMember::class);
    }

    public function joinedMembers(): HasMany
    {
        return $this->members()->where('status', 'joined');
    }

    public function broadcasts(): HasMany
    {
        return $this->hasMany(CauseBroadcast::class);
    }

    public function isPaid(): bool
    {
        return in_array($this->payment_status, ['paid', 'waived'], true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function markPaid(?string $paymentReference = null): void
    {
        $this->update([
            'payment_status' => 'paid',
            'status' => 'active',
            'payment_reference' => $paymentReference,
            'paid_at' => now(),
        ]);
    }

    public function waivePayment(string $reason): void
    {
        $this->update([
            'payment_status' => 'waived',
            'status' => 'active',
            'payment_waived_reason' => $reason,
            'paid_at' => now(),
        ]);
    }

    public function memberFor(int $userId): ?CauseMember
    {
        return $this->members()->where('user_id', $userId)->first();
    }

    /** Title/goal/description search, for "search causes and goals" self-serve discovery. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (!$term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('goal', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
    }

    public function scopeDiscoverable(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
