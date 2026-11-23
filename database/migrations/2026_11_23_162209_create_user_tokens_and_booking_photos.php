<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The staff app ("826 Team") signs a team member in with a bearer token
     * (only its sha256 is kept, 30 days sliding); units get photos from the
     * team; and a booking's history note may be longer than 255 characters.
     */
    public function up(): void
    {
        Schema::create('user_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The device, as the app names it ("Galaxy S24")
            $table->string('name', 120)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('booking_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['booking_id', 'id']);
        });

        Schema::table('booking_events', function (Blueprint $table) {
            $table->text('note')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('booking_events', function (Blueprint $table) {
            $table->string('note')->nullable()->change();
        });

        Schema::dropIfExists('booking_photos');
        Schema::dropIfExists('user_tokens');
    }
};
