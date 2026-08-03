<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring named settings/locations (e.g. "The Sentinel's Underground
 * Vault", "Ashgrove Manor Library") — the environment counterpart to
 * `characters`. Same shape, same lifecycle: ImagePromptAgent identifies
 * them from the story text, a reference image gets generated once, and
 * every later scene set there reuses it (or, if the active image
 * provider can't accept reference pixels, its image_prompt gets folded
 * into the scene prompt as text instead — see
 * App\Models\Concerns\HasReferenceImage::definitionText()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('story_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('story_series')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('img_url')->nullable();
            $table->string('img_url_quality')->nullable();
            $table->text('image_prompt')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->text('last_generation_error')->nullable();
            $table->unsignedInteger('generation_attempts')->default(0);

            $table->timestamps();

            $table->index(['story_id', 'name'], 'environments_story_name_idx');
            $table->index(['series_id', 'name'], 'environments_series_name_idx');
            $table->index('generated_at', 'environments_generated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};
