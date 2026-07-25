<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Password is now optional — Google-only accounts have none.
            $table->string('password')->nullable()->change();

            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('avatar_url')->nullable()->after('google_id');

            $table->string('phone')->nullable()->unique()->after('avatar_url');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');

            // OTP used for both signup verification and password recovery.
            $table->string('otp_code')->nullable()->after('phone_verified_at');
            $table->timestamp('otp_expires_at')->nullable()->after('otp_code');
            $table->string('otp_channel')->nullable()->after('otp_expires_at'); // 'email' | 'sms'
            $table->string('otp_purpose')->nullable()->after('otp_channel'); // 'signup' | 'password_reset'
            $table->unsignedInteger('otp_attempts')->default(0)->after('otp_purpose');

            $table->string('role')->default('user')->after('otp_attempts'); // 'user' | 'admin'
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'google_id', 'avatar_url', 'phone', 'phone_verified_at',
                'otp_code', 'otp_expires_at', 'otp_channel', 'otp_purpose', 'otp_attempts', 'role',
            ]);
        });
    }
};
