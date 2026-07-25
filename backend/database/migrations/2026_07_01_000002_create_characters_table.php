<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Reference image used to keep the character visually consistent across
            // generated images (sent to Gemini as an image part when available).
            $table->string('img_url')->nullable();
            $table->timestamps();

            $table->index(['story_id', 'name'], 'characters_story_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
