<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CauseMember extends Model
{
    protected $fillable = ['cause_id', 'user_id', 'invited_by', 'status', 'invited_at', 'joined_at', 'opted_out_at'];

    protected $casts = [
        'invited_at' => 'datetime',
        'joined_at' => 'datetime',
        'opted_out_at' => 'datetime',
    ];

    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isJoined(): bool
    {
        return $this->status === 'joined';
    }

    public function join(): void
    {
        $this->update(['status' => 'joined', 'joined_at' => now(), 'opted_out_at' => null]);
    }

    public function decline(): void
    {
        $this->update(['status' => 'declined']);
    }

    public function optOut(): void
    {
        $this->update(['status' => 'opted_out', 'opted_out_at' => now()]);
    }
}
