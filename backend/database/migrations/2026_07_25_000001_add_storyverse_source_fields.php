<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a StorySeries / Story be traced back to the StoryVerse story it was
 * imported from, and makes re-imports idempotent (upsert on source_slug /
 * story_link rather than duplicating rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_series', function (Blueprint $table) {
            // Where this series came from, e.g. "storyverse". Null for series
            // created the old way (manual Medium links).
            $table->string('source')->nullable()->after('description');
            // The StoryVerse *series* slug (stable identity for re-imports),
            // distinct from `slug` above which is CraftProfessor's own.
            $table->string('source_slug')->nullable()->unique()->after('source');
            $table->string('cover_image_url')->nullable()->after('source_slug');
            $table->string('external_url')->nullable()->after('cover_image_url');
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->string('title')->nullable()->after('episode_number');
            $table->string('source')->nullable()->after('title');
            $table->timestamp('published_at')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('story_series', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_slug', 'cover_image_url', 'external_url']);
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['title', 'source', 'published_at']);
        });
    }
};
