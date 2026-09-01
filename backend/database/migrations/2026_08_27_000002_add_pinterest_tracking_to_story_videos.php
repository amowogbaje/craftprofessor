<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_videos', function (Blueprint $table) {
            $table->boolean('posted_to_pinterest')->default(false)->after('generated_at');
            $table->timestamp('pinterest_posted_at')->nullable()->after('posted_to_pinterest');
            $table->string('pinterest_pin_id')->nullable()->after('pinterest_posted_at');
            $table->text('last_pinterest_error')->nullable()->after('pinterest_pin_id');
        });
    }

    public function down(): void
    {
        Schema::table('story_videos', function (Blueprint $table) {
            $table->dropColumn(['posted_to_pinterest', 'pinterest_posted_at', 'pinterest_pin_id', 'last_pinterest_error']);
        });
    }
};
