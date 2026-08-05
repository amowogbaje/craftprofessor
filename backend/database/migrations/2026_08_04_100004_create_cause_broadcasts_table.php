<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row = "post this cause's media, through this member's connected
 * account on this platform, at this time." The `causes:post-due` cron
 * command (App\Console\Commands\PostDueCauseBroadcasts) picks up any row
 * whose scheduled_at (stored UTC) has arrived and hands it to
 * SocialContentRouter — see App\Services\Causes\CauseBroadcastService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cause_broadcasts', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('cause_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cause_media_id')->constrained()->cascadeOnDelete();

            // Whose connected account broadcasts this — must be a joined member.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // pinterest | linkedin | twitter | youtube | instagram | facebook

            // Who scheduled it — the member themselves, or the cause owner
            // scheduling on a member's behalf.
            $table->foreignId('scheduled_by')->constrained('users')->cascadeOnDelete();

            // Stored UTC; `timezone` records what the scheduler picked at
            // schedule-time so it can be redisplayed correctly.
            $table->timestamp('scheduled_at');
            $table->string('timezone')->default('UTC');

            $table->enum('status', ['scheduled', 'posted', 'failed', 'skipped'])->default('scheduled');
            $table->foreignId('social_post_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['cause_id', 'cause_media_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cause_broadcasts');
    }
};
