<?php

namespace App\Models;

use App\Models\Concerns\HasReferenceImage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring named setting/location (e.g. "The Sentinel's Underground
 * Vault"). Structurally and behaviorally identical to Character — see
 * App\Models\Concerns\HasReferenceImage — just a different asset type so
 * scene generation can keep a location visually consistent the same way
 * it already does for faces.
 *
 * story_id records which episode this environment was first introduced
 * in, same convention as Character::story_id — NOT an ownership scope.
 * series_id (when set) is what Story::knownEnvironments() uses to find
 * environments across every episode of a series.
 */
class Environment extends Model
{
    use HasFactory;
    use HasReferenceImage;

    protected $fillable = [
        'story_id',
        'series_id',
        'user_id',
        'name',
        'img_url',
        'img_url_quality',
        'image_prompt',
        'generated_at',
        'last_generation_error',
        'generation_attempts',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(StorySeries::class, 'series_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
