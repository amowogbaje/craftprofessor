<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->foreignId('story_video_id')->nullable()->after('video_id')
                ->constrained()->cascadeOnDelete();

            $table->unique(['story_video_id', 'platform'], 'social_posts_unique_story_video_target');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropUnique('social_posts_unique_story_video_target');
            $table->dropConstrainedForeignId('story_video_id');
        });
    }
};
