<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account posting preference: let the AI pick the best board(s) per
 * image ("dynamic"), or always post to a fixed set the user chose
 * ("fixed"). Only meaningful for board-based platforms (Pinterest today);
 * harmless no-op for everything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->enum('board_posting_mode', ['dynamic', 'fixed'])->default('dynamic')->after('board_id');
        });

        Schema::create('pinterest_board_social_account', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pinterest_board_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['social_account_id', 'pinterest_board_id'], 'pb_sa_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinterest_board_social_account');

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('board_posting_mode');
        });
    }
};
