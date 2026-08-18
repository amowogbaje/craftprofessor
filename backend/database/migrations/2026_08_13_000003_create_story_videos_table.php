<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_videos', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            // One assembled video per story. New attempts overwrite this
            // row rather than accumulating — see StoryVideoAssemblyService.
            $table->foreignId('story_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('video_url')->nullable();
            $table->decimal('duration_seconds', 8, 2)->nullable();
            $table->unsignedInteger('scene_count')->default(0);

            // 'pending' -> 'processing' -> 'ready' | 'failed'
            $table->string('status')->default('pending');
            $table->text('last_generation_error')->nullable();
            $table->unsignedInteger('generation_attempts')->default(0);
            $table->timestamp('generated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_videos');
    }
};
