<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_image_prompts', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');

            // Only set once ImageGeneratorService successfully generates the image.
            // Left null on any failure so Scheduler 3 will retry this row.
            $table->string('image_generated_url')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->text('last_generation_error')->nullable();
            $table->unsignedInteger('generation_attempts')->default(0);

            // Character ids referenced by this specific prompt (JSON array of character.id)
            $table->json('main_character_ids')->nullable();

            // Pinterest metadata, produced by ImagePromptAgent alongside the prompt.
            $table->string('pinterest_title')->nullable();
            $table->text('pinterest_description')->nullable();
            $table->string('pinterest_link')->nullable();

            // Scheduler 4 bookkeeping — caps posting at 2/day.
            $table->boolean('posted_to_pinterest')->default(false);
            $table->timestamp('pinterest_posted_at')->nullable();
            $table->string('pinterest_pin_id')->nullable();
            $table->text('last_pinterest_error')->nullable();

            $table->timestamps();

            // Explicit short names — MySQL's default "{table}_{cols}_index"
            // naming exceeds its 64-char identifier limit on this table.
            $table->index(['story_id', 'image_generated_url'], 'sip_story_img_url_idx');
            $table->index(['posted_to_pinterest', 'image_generated_url'], 'sip_posted_img_url_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_image_prompts');
    }
};
