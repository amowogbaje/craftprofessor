<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('medium_link')->unique();
            $table->longText('story_text')->nullable();
            // Set true once Scheduler 2 has generated the 10 image prompts for this story.
            $table->boolean('prompt_generated')->default(false);
            // Bumped every failed Medium fetch attempt so we can eventually give up / alert.
            $table->unsignedInteger('fetch_attempts')->default(0);
            $table->text('last_fetch_error')->nullable();
            $table->timestamps();

            $table->index('prompt_generated', 'stories_prompt_generated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
