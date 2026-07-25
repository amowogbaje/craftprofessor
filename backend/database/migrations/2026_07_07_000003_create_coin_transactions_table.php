<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_transactions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type'); // 'credit' | 'debit'
            $table->string('reason'); // 'purchase' | 'image_prompt' | 'image_generation' | 'video_prompt' | 'video_generation' | 'refund' | 'admin_adjustment'
            $table->bigInteger('amount'); // always positive; sign implied by `type`
            $table->unsignedBigInteger('balance_after');

            // Polymorphic-ish reference to whatever this charge/credit was for
            // (a StoryImagePrompt, a Video, a Flutterwave payment, etc).
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // For Flutterwave purchases: their tx_ref / transaction id, for reconciliation.
            $table->string('provider_reference')->nullable()->index();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_transactions');
    }
};
