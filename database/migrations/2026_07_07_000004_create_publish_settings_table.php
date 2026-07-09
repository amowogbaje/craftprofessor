<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_settings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedInteger('daily_image_limit')->default(3);
            $table->unsignedInteger('daily_video_limit')->default(1);

            // Optional hard ceilings independent of coin balance (0 = unlimited, only coins gate it).
            $table->unsignedInteger('monthly_image_limit')->nullable();
            $table->unsignedInteger('monthly_video_limit')->nullable();

            $table->string('timezone')->default('UTC');
            $table->boolean('auto_publish')->default(false); // if false, generated content sits as "draft" until user schedules/publishes it

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_settings');
    }
};
