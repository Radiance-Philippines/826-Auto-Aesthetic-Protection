<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Android app's APKs, uploaded in /admin → App releases and served
     * at /download. Size and sha256 are worked out from the stored file.
     */
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20);
            $table->unsignedInteger('version_code')->unique();
            $table->string('apk_path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->text('notes')->nullable();
            // Apps older than this release must update before they continue
            $table->boolean('mandatory')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
