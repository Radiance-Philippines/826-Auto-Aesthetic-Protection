<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer app signs in with a bearer token (only its sha256 is
     * kept), and may register a push token per device.
     */
    public function up(): void
    {
        Schema::create('guest_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            // The device, as the app names it ("Pixel 8")
            $table->string('name', 120)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('token', 255)->unique();
            // android or ios
            $table->string('platform', 10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('guest_tokens');
    }
};
