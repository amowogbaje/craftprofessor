<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // Null = standalone story. Set = this story is one episode of a series.
            $table->foreignId('series_id')->nullable()->after('id')
                ->constrained('story_series')->nullOnDelete();
            $table->unsignedInteger('episode_number')->nullable()->after('series_id');

            $table->index(['series_id', 'episode_number'], 'stories_series_episode_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn('episode_number');
        });
    }
};
