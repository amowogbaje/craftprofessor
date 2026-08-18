<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_image_prompts', function (Blueprint $table) {
            // The voiceover/narration line read over this scene once we
            // stitch scenes into a video with audio. Distinct from
            // `caption` (an on-image text overlay) — narration is spoken,
            // never rendered into the image itself.
            $table->text('narration')->nullable()->after('caption');

            // Ordering within the story. We no longer generate a fixed
            // count of scenes (was hardcoded to 10 via ImagePromptAgent's
            // schema) — ImagePromptAgent now decides however many scenes
            // the story's beats actually need, so an explicit order column
            // replaces relying on row id/created_at.
            $table->unsignedInteger('scene_number')->nullable()->after('narration');
        });
    }

    public function down(): void
    {
        Schema::table('story_image_prompts', function (Blueprint $table) {
            $table->dropColumn(['narration', 'scene_number']);
        });
    }
};
