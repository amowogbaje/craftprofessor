<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StorySeries extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'slug', 'description'];

    protected static function booted(): void
    {
        static::creating(function (StorySeries $series) {
            $series->slug ??= Str::slug($series->title) . '-' . Str::random(4);
        });
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class, 'series_id')->orderBy('episode_number');
    }

    /** Characters shared across every episode of this series. */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class, 'series_id');
    }
}
