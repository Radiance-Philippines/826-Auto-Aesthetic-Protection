<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers can also have their own account with an email and a
     * password (Google stays). Additive only: every existing row keeps
     * everything and has no password.
     *
     * login_email holds the lower-cased email of a password account and is
     * unique, so two password accounts never share an address, while
     * walk-in customers and Google customers may still share one with
     * somebody (no partial index needed, so it works on MySQL too).
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('password')->nullable();
            $table->string('login_email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
        });

        Schema::create('guest_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_password_reset_tokens');

        Schema::table('guests', function (Blueprint $table) {
            $table->dropUnique(['login_email']);
            $table->dropColumn(['password', 'login_email', 'email_verified_at']);
        });
    }
};
