<?php

use App\Models\Story;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('title');
        });

        // Backfill existing rows — new rows get one automatically via
        // Story::booted()'s creating hook, same pattern as StorySeries.
        Story::whereNull('slug')->orderBy('id')->each(function (Story $story) {
            $story->slug = Story::uniqueSlugFor($story->title ?: "story-{$story->id}");
            $story->saveQuietly();
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
