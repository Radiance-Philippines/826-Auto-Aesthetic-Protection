<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referral codes: the owner turns them on and sets the discount and the
     * points; nothing is filled in until they do. A redemption is one
     * booking that used someone's code.
     */
    public function up(): void
    {
        Schema::create('referral_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            // fixed (pesos) or percent
            $table->string('discount_type', 10)->default('fixed');
            $table->unsignedInteger('discount_value')->nullable();
            // Points the code's owner earns when the booking exits; null = none
            $table->unsignedInteger('owner_points')->nullable();
            $table->timestamps();
        });

        Schema::create('referral_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('code_guest_id')->constrained('guests')->cascadeOnDelete();
            $table->foreignId('used_by_guest_id')->constrained('guests')->cascadeOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            // Blank until the booking is priced
            $table->unsignedInteger('discount_amount')->nullable();
            $table->integer('points_awarded')->default(0);
            $table->timestamp('awarded_at')->nullable();
            // The booking was cancelled, declined or expired: nothing is earned
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('referral_guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->unsignedInteger('discount_amount')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referral_guest_id');
            $table->dropColumn('discount_amount');
        });

        Schema::dropIfExists('referral_redemptions');
        Schema::dropIfExists('referral_settings');
    }
};
