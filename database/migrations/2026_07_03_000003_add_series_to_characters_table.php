<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A character introduced in a series episode should be reused across every
 * other episode in that series, not recreated per-story. This adds
 * characters.series_id (denormalized from the owning story) so lookups can
 * be scoped to "this series" instead of "this story" when one exists.
 *
 * It also loosens characters.story_id from cascadeOnDelete to nullOnDelete
 * and makes it nullable: story_id now just records which episode a
 * character was *first introduced in*, not an ownership link — deleting
 * that origin story should no longer delete a character that other
 * episodes still reference.
 *
 * NOTE: changing an existing column (->change()) requires doctrine/dbal —
 * `composer require doctrine/dbal` if you don't already have it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('series_id')->nullable()->after('story_id')
                ->constrained('story_series')->nullOnDelete();

            $table->index(['series_id', 'name'], 'characters_series_name_idx');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->dropForeign(['story_id']);
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('story_id')->nullable()->change();
            $table->foreign('story_id')->references('id')->on('stories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropForeign(['story_id']);
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('story_id')->nullable(false)->change();
            $table->foreign('story_id')->references('id')->on('stories')->cascadeOnDelete();
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('series_id');
        });
    }
};
