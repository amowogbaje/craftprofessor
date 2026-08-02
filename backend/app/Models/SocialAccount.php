<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'provider_user_id', 'provider_username', 'board_id',
        'board_posting_mode', 'access_token', 'refresh_token', 'token_expires_at',
        'scopes', 'meta', 'connected_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'scopes' => 'array',
        'meta' => 'array',
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Every board this account is known to have on Pinterest. */
    public function boards(): HasMany
    {
        return $this->hasMany(PinterestBoard::class);
    }

    /** The boards the user picked when board_posting_mode = 'fixed'. */
    public function preferredBoards(): BelongsToMany
    {
        return $this->belongsToMany(PinterestBoard::class, 'pinterest_board_social_account');
    }

    public function isDynamicBoardPosting(): bool
    {
        return $this->board_posting_mode === 'dynamic';
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }
}