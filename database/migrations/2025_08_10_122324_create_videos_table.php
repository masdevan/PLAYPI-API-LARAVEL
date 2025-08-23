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

            $table->string('360_path')->nullable();
            $table->string('360_mime_type')->nullable();
            $table->bigInteger('360_size')->nullable();
            $table->string('360_safelink')->nullable();

            $table->string('480_path')->nullable();
            $table->string('480_mime_type')->nullable();
            $table->bigInteger('480_size')->nullable();
            $table->string('480_safelink')->nullable();

            $table->string('720_path')->nullable();
            $table->string('720_mime_type')->nullable();
            $table->bigInteger('720_size')->nullable();
            $table->string('720_safelink')->nullable();

            $table->string('1080_path')->nullable();
            $table->string('1080_mime_type')->nullable();
            $table->bigInteger('1080_size')->nullable();
            $table->string('1080_safelink')->nullable();

            $table->boolean('status')->default(0)->comment('0: processing, 1: ready');
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
