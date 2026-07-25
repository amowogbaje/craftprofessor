<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Bumped on password change / "log out everywhere". Every JWT
            // carries the token_version it was issued at ('tv' claim); a
            // mismatch means the token predates a forced logout and is rejected.
            $table->unsignedInteger('token_version')->default(0)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('token_version');
        });
    }
};
