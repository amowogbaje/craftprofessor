<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generalizes what story_image_prompts.posted_to_pinterest / pinterest_pin_id
 * / pinterest_posted_at / last_pinterest_error used to track for Pinterest
 * only, to any platform — and, for Pinterest specifically, to *multiple*
 * boards per image (up to 3, enforced in code — see PinterestPlatform).
 *
 * story_image_prompts.pinterest_* columns are left in place untouched as a
 * cheap "has at least one pin gone out" flag (existing code — the daily
 * cap job, link stats, etc. — keeps working unmodified) while this table
 * becomes the source of truth for anything that needs the full picture:
 * every attempt, on every platform, to every board.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('story_image_prompt_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->cascadeOnDelete();

            // 'pinterest', 'linkedin', 'twitter', 'youtube', 'instagram', 'facebook', ...
            $table->string('platform');

            // Only set for board-based platforms (Pinterest). Null everywhere else.
            $table->foreignId('pinterest_board_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('status', ['pending', 'posted', 'failed'])->default('pending');
            $table->string('external_post_id')->nullable(); // pin id / tweet id / video id / post id...
            $table->text('error')->nullable();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            // Same image, same platform, same board: only once. (board_id
            // being nullable means this doesn't stop a non-board platform
            // from having multiple rows if ever needed — nullable columns
            // aren't compared as equal by unique indexes, which is fine
            // here since non-Pinterest platforms only ever get one row per
            // image via application logic, not a DB constraint.)
            $table->unique(
                ['story_image_prompt_id', 'platform', 'pinterest_board_id'],
                'social_posts_unique_target'
            );

            $table->index(['user_id', 'platform', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
