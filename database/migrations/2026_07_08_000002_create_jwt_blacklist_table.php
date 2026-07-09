<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jwt_blacklist', function (Blueprint $table) {
            $table->id();
            $table->string('jti')->unique(); // the token's unique id (JWT 'jti' claim)
            $table->timestamp('expires_at'); // copy of the token's own expiry — row can be pruned after this
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jwt_blacklist');
    }
};
