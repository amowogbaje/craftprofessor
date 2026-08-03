<?php

namespace App\Models;

use App\Models\Concerns\HasReferenceImage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring significant object (a specific sword, a locket, a book).
 * Structurally and behaviorally identical to Character/Environment — see
 * App\Models\Concerns\HasReferenceImage and Environment's docblock, which
 * both apply here unchanged apart from the asset type.
 */
class Prop extends Model
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
