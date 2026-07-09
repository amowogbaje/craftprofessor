<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Coins are an integer currency (1 coin = smallest chargeable unit).
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('lifetime_purchased')->default(0);
            $table->unsignedBigInteger('lifetime_spent')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
