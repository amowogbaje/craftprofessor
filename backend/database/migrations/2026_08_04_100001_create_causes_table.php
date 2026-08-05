<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('causes', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            // Short statement of what the cause is trying to achieve — searchable
            // alongside title/description so members can find causes by goal.
            $table->string('goal')->nullable();

            // A cause only starts accepting members/broadcasting once payment
            // clears (or is waived) — see payment_status below.
            $table->enum('status', ['pending_payment', 'active', 'paused', 'archived'])
                ->default('pending_payment');

            $table->decimal('creation_fee_amount', 10, 2)->default(10.00);
            $table->string('creation_fee_currency', 3)->default('USD');
            $table->enum('payment_status', ['pending', 'paid', 'waived'])->default('pending');
            $table->string('payment_reference')->nullable();
            $table->string('payment_waived_reason')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('causes');
    }
};
