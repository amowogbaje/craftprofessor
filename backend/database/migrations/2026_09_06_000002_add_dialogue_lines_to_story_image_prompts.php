<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional multi-speaker dialogue for a scene, alongside (not replacing)
 * `narration`. See App\Ai\Agents\ImagePromptAgent for how this gets
 * populated, App\Services\NarrationAudioService for how each line becomes
 * its own TTS clip (voiced per-character via characters.voice) that then
 * get concatenated, and App\Services\StoryVideoAssemblyService for how
 * dialogue lines become "NAME: ..." caption cards timed to each line's own
 * measured audio duration instead of one narration blob split by word
 * count. This is the extension point that class's own doc-comment already
 * called out ("DIALOGUE, LATER").
 *
 * Shape once fully populated (each entry):
 *   {"character_name": "Alice", "text": "Wait — did you hear that?",
 *    "audio_url": "https://.../narration-audio/12/7-ab12cd34.mp3",
 *    "audio_seconds": 1.8}
 * ImagePromptAgent only ever fills in character_name/text; audio_url/
 * audio_seconds are added by NarrationAudioService once each line's clip
 * is generated. Null/empty for any scene the story generator decided
 * didn't call for back-and-forth dialogue — narration alone is still a
 * perfectly normal, more common scene shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_image_prompts', function (Blueprint $table) {
            $table->json('dialogue_lines')->nullable()->after('narration');
        });
    }

    public function down(): void
    {
        Schema::table('story_image_prompts', function (Blueprint $table) {
            $table->dropColumn('dialogue_lines');
        });
    }
};
