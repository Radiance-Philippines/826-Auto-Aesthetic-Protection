<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sign in with Apple (guests.apple_id), the app's own Google client ids
     * and the Apple client id in Settings, and a customer on the security trail
     * (account deletion has no admin user behind it).
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('apple_id')->nullable()->unique();
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('google_android_client_id')->nullable();
            $table->string('google_ios_client_id')->nullable();
            $table->string('apple_client_id')->nullable();
        });

        Schema::table('security_events', function (Blueprint $table) {
            // No foreign key: the trail outlives the customer's row
            $table->unsignedBigInteger('guest_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->dropIndex(['guest_id']);
            $table->dropColumn('guest_id');
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['google_android_client_id', 'google_ios_client_id', 'apple_client_id']);
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->dropUnique(['apple_id']);
            $table->dropColumn('apple_id');
        });
    }
};
