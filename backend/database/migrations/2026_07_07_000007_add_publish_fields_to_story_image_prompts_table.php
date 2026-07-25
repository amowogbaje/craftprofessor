<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_image_prompts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('story_id')->constrained()->cascadeOnDelete();

            // 'draft' -> 'scheduled' -> 'published' (feed label). Failed generation
            // stays out of this entirely (image_generated_url is just null).
            $table->string('status')->default('draft')->after('image_generated_url');
            $table->timestamp('scheduled_at')->nullable()->after('status');
            $table->timestamp('published_at')->nullable()->after('scheduled_at');

            $table->unsignedInteger('prompt_coin_cost')->default(0)->after('generation_attempts');
            $table->unsignedInteger('image_coin_cost')->default(0)->after('prompt_coin_cost');

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('story_image_prompts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['status', 'scheduled_at', 'published_at', 'prompt_coin_cost', 'image_coin_cost']);
        });
    }
};
