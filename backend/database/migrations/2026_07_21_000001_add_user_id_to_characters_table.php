<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * characters had no user_id of its own — the owner could only be reached
 * via story_id (and, for series characters, series_id). That's fine for
 * display, but it means the image generation queue (Scheduler 3) can't
 * cheaply exclude "this user is rate-limited/out of coins" without a join
 * per row. story_image_prompts already carries user_id for exactly this
 * reason (see 2026_07_07_000007); this brings characters in line with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('story_id')->constrained()->nullOnDelete();
        });

        // Backfill: prefer the series owner (series characters are shared
        // across episodes, some of which may belong to different stories
        // but the same series/user), falling back to the origin story's
        // owner for standalone characters.
        DB::statement(<<<'SQL'
            UPDATE characters
            LEFT JOIN story_series ON story_series.id = characters.series_id
            LEFT JOIN stories ON stories.id = characters.story_id
            SET characters.user_id = COALESCE(story_series.user_id, stories.user_id)
        SQL);

        Schema::table('characters', function (Blueprint $table) {
            $table->index(['user_id', 'img_url'], 'characters_user_img_idx');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropIndex('characters_user_img_idx');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
