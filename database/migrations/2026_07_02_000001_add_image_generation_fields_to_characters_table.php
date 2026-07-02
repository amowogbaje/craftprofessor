<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // Prompt used to generate this character's reference/face image.
            // Produced by ImagePromptAgent alongside the story's 10 scene prompts.
            $table->text('image_prompt')->nullable()->after('img_url');
            $table->timestamp('generated_at')->nullable()->after('image_prompt');
            $table->text('last_generation_error')->nullable()->after('generated_at');
            $table->unsignedInteger('generation_attempts')->default(0)->after('last_generation_error');

            $table->index('generated_at', 'characters_generated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['image_prompt', 'generated_at', 'last_generation_error', 'generation_attempts']);
        });
    }
};
