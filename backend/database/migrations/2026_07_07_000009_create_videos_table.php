<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_prompt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('story_image_prompt_id')->constrained()->cascadeOnDelete();

            $table->string('video_url')->nullable();
            $table->string('provider')->default('veo'); // 'veo' for now, room to add others later
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->string('status')->default('draft'); // 'draft' -> 'scheduled' -> 'published' (feed label)
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->unsignedInteger('coin_cost')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->text('last_generation_error')->nullable();
            $table->unsignedInteger('generation_attempts')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
