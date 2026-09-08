<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A character's assigned TTS voice — meaningful in the namespace of
 * whatever config('ai.default_tts_provider') is active (e.g. "Puck" for
 * Gemini, "verse" for OpenAI, an ElevenLabs voice id). See
 * ImageGeneratorService::pickVoiceForNewCharacter() for where this gets
 * auto-assigned, and NarrationAudioService for where it's read back when
 * synthesizing a scene's dialogue lines.
 *
 * Deliberately just one plain string column rather than a provider-keyed
 * map — this app runs one TTS provider at a time
 * (config('ai.default_tts_provider')), so a character only ever needs one
 * active voice. Switching providers means existing characters' voices no
 * longer resolve to anything meaningful for the new provider and need
 * reassigning — same as switching TTS_PROVIDER already invalidates any
 * assumption about voice naming elsewhere in this app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('voice')->nullable()->after('img_url');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('voice');
        });
    }
};
