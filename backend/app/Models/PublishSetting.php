<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'daily_image_limit', 'daily_video_limit',
        'monthly_image_limit', 'monthly_video_limit', 'timezone', 'auto_publish',
    ];

    protected $casts = [
        'auto_publish' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
