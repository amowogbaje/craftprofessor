<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_image_prompts', function (Blueprint $table) {
            $table->json('main_environment_ids')->nullable()->after('main_character_ids');
            $table->json('main_prop_ids')->nullable()->after('main_environment_ids');
        });
    }

    public function down(): void
    {
        Schema::table('story_image_prompts', function (Blueprint $table) {
            $table->dropColumn(['main_environment_ids', 'main_prop_ids']);
        });
    }
};
