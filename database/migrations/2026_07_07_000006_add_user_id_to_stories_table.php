<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // Set directly for standalone stories; for series stories this is
            // kept in sync with the series owner (see Story::booted()).
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();

            // The raw text a user pastes in when they want the app to generate
            // prompts/images from their own story instead of a Medium link.
            $table->text('user_supplied_text')->nullable()->after('story_text');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('user_supplied_text');
        });
    }
};
