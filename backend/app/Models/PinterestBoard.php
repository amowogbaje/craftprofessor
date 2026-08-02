<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PinterestBoard extends Model
{
    protected $fillable = [
        'user_id',
        'social_account_id',
        'external_board_id',
        'name',
        'description',
        'topics',
        'source',
        'is_active',
    ];

    protected $casts = [
        'topics' => 'array',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /** Accounts that have this board pinned as a "fixed" preferred board. */
    public function preferredByAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class, 'pinterest_board_social_account');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
