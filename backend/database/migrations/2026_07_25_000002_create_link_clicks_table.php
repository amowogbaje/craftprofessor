<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_clicks', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();

            // Owner of the destination link, resolved from the `story` match
            // below (or null if we couldn't attribute it to anyone, e.g. a
            // manually-shared link that isn't a known story_link).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Which channel drove the click: "Pinterest", "LinkedIn", etc.
            // Free-text (not an enum) since new networks get added often.
            $table->string('social_media');

            // The final destination the visitor is redirected to.
            $table->text('target_url');

            // Best-effort attribution back to the story/pin that owns this
            // link, when the target_url matches a known story_link.
            $table->foreignId('story_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('story_image_prompt_id')->nullable()->constrained()->nullOnDelete();

            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referrer')->nullable();

            $table->timestamp('clicked_at')->useCurrent();

            $table->index(['user_id', 'social_media'], 'link_clicks_user_social_idx');
            $table->index('clicked_at', 'link_clicks_clicked_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_clicks');
    }
};
