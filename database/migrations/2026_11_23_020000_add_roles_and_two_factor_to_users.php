<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles replace the owner flag (which stays, kept in step with the
     * super admin role): owners become super admins and everyone else a
     * branch manager, which is what they already do. permissions is the
     * per-account override of the role's areas (null = the role's default).
     * The two-factor columns are Filament's own (app code, recovery codes,
     * email code).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('branch_manager')->after('is_owner');
            $table->json('permissions')->nullable()->after('role');
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            $table->boolean('has_email_authentication')->default(false);
        });

        DB::table('users')->where('is_owner', true)->update(['role' => 'super_admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'permissions', 'app_authentication_secret', 'app_authentication_recovery_codes', 'has_email_authentication']);
        });
    }
};
