<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CauseMedia extends Model
{
    protected $fillable = ['cause_id', 'uploaded_by', 'title', 'details', 'url', 'type', 'link_url'];

    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function broadcasts(): HasMany
    {
        return $this->hasMany(CauseBroadcast::class);
    }

    public function isVideo(): bool
    {
        return $this->type === 'video';
    }
}
