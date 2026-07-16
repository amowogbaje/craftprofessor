<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // Full-size JPEG for download/reuse, alongside the existing
            // img_url (which now holds the small WebP display copy).
            $table->string('img_url_quality')->nullable()->after('img_url');
        });

        Schema::table('story_image_prompts', function (Blueprint $table) {
            $table->string('image_generated_url_quality')->nullable()->after('image_generated_url');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('img_url_quality');
        });

        Schema::table('story_image_prompts', function (Blueprint $table) {
            $table->dropColumn('image_generated_url_quality');
        });
    }
};