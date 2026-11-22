<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The booking activity log, the admin bell (Filament database
     * notifications), points redemptions, the owner's peso value per point
     * (blank = redeeming for a discount is off) and the security settings.
     */
    public function up(): void
    {
        Schema::create('booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30);
            $table->string('from')->nullable();
            $table->string('to')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['booking_id', 'id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('points');
            $table->unsignedInteger('peso_value')->nullable();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('referral_settings', function (Blueprint $table) {
            $table->unsignedInteger('peso_per_point')->nullable();
        });

        Schema::create('security_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('require_two_factor')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_settings');

        Schema::table('referral_settings', function (Blueprint $table) {
            $table->dropColumn('peso_per_point');
        });

        Schema::dropIfExists('reward_redemptions');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('booking_events');
    }
};
