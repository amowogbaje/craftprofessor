<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring significant objects (a specific sword, a locket, a book) —
 * same shape and lifecycle as `characters`/`environments`. See that
 * migration's docblock; this is identical apart from the table name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('props', function (Blueprint $table) {
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

            $table->index(['story_id', 'name'], 'props_story_name_idx');
            $table->index(['series_id', 'name'], 'props_series_name_idx');
            $table->index('generated_at', 'props_generated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('props');
    }
};
