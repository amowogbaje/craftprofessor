<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user toggle: should per-scene AI video clips (Veo/Agnes motion
 * clips) be generated automatically as scenes become ready, or only when
 * the user manually triggers it from the dashboard (the only behavior
 * that existed before this)?
 *
 * Deliberately does NOT add a separate cost/rate cap of its own — a user
 * who turns this on is still bound by the daily_video_limit/
 * monthly_video_limit columns already on this same table (see
 * UsageLimitService::canGenerateVideo(), already called by
 * SceneVideoGenerationService::generate() before every attempt) and by
 * their coin balance. Automatic generation just means "don't wait for me
 * to click the button," not "ignore my existing limits" — those limits
 * were already the right lever for bounding cost, this just adds a second
 * way to reach them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publish_settings', function (Blueprint $table) {
            $table->boolean('auto_generate_scene_videos')->default(false)->after('auto_publish');
        });
    }

    public function down(): void
    {
        Schema::table('publish_settings', function (Blueprint $table) {
            $table->dropColumn('auto_generate_scene_videos');
        });
    }
};
