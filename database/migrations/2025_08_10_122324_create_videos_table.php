<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->string('title');

            $table->string('filename');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->text('video_signed_url')->nullable();
            $table->timestamp('video_expires_at')->nullable();

            $table->string('image_path')->nullable();
            $table->bigInteger('size')->nullable();
            $table->text('image_signed_url')->nullable();
            $table->timestamp('image_expires_at')->nullable();

            $table->boolean('status')->default(0)->comment('0: processing, 1: ready');
            $table->string('safelink')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
