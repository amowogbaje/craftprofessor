<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets Cause broadcasts land in the same social_posts ledger every other
 * platform post uses, instead of a parallel bookkeeping table — so
 * anything that already reports off social_posts (future stats, etc.)
 * sees Cause activity for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->foreignId('cause_broadcast_id')->nullable()->after('pinterest_board_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cause_broadcast_id');
        });
    }
};
