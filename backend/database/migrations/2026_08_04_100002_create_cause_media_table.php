<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cause_media', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('cause_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();

            $table->string('title');
            $table->text('details')->nullable();
            $table->string('url', 2048);
            $table->enum('type', ['image', 'video']);
            // Where a broadcast of this media should link back to (e.g. the cause page).
            $table->string('link_url', 2048)->nullable();

            $table->timestamps();

            $table->index(['cause_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cause_media');
    }
};
