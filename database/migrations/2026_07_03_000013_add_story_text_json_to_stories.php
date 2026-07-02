<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->json('story_text_json')->nullable()->before('story_text'); // raw Medium JSON, for debugging    
            $table->string('text_source')->nullable()->after('story_text_json'); // html | json
            $table->timestamp('processed_at')->nullable()->after('text_source');            
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('story_text_json');
            $table->dropColumn('text_source');
            $table->dropColumn('processed_at');
        });
    }
};
