<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_image_prompts', function (Blueprint $table) {
            $table->string('narration_audio_url')->nullable()->after('narration');
            // Duration in seconds, measured off the generated audio file
            // (not estimated from word count) — used by StoryVideoAssemblyService
            // to know how long to hold/trim each scene's clip so picture and
            // narration stay in sync.
            $table->decimal('narration_audio_seconds', 6, 2)->nullable()->after('narration_audio_url');
            $table->text('last_narration_error')->nullable()->after('narration_audio_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('story_image_prompts', function (Blueprint $table) {
            $table->dropColumn(['narration_audio_url', 'narration_audio_seconds', 'last_narration_error']);
        });
    }
};
