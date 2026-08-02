<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's known Pinterest boards — synced from Pinterest and/or created
 * by BoardSelectionAgent on the fly. This is the "board_list" the AI picks
 * from when deciding where a finished image should be pinned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinterest_boards', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();

            $table->string('external_board_id'); // Pinterest's own board id
            $table->string('name');
            $table->text('description')->nullable();

            // Free-form topic/style keywords the AI board-picker matches
            // image prompts against, e.g. ["romance", "dark academia", "fantasy"].
            // Populated by BoardSelectionAgent when it creates a board, and
            // editable by the user afterwards.
            $table->json('topics')->nullable();

            // Was this board created by CraftProfessor (via the AI picker)
            // or synced in from an existing Pinterest board?
            $table->enum('source', ['synced', 'ai_created'])->default('synced');

            // Users can retire a board from the AI's candidate pool without
            // disconnecting/deleting it from Pinterest.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['social_account_id', 'external_board_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinterest_boards');
    }
};
