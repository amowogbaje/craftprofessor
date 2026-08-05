<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cause_members', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('cause_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();

            // invited: awaiting the user's response.
            // joined: actively a member — their connected accounts are eligible
            //   to broadcast the cause's media.
            // declined: never joined.
            // opted_out: was joined, chose to leave — no longer eligible.
            $table->enum('status', ['invited', 'joined', 'declined', 'opted_out'])->default('invited');

            $table->timestamp('invited_at')->useCurrent();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();

            $table->timestamps();

            $table->unique(['cause_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cause_members');
    }
};
