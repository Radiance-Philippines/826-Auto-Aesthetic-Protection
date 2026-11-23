<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A token remembers the team app it was issued for (config/team_apps.php).
     * Null = the legacy 826 Team app, which sends no X-Team-App header.
     */
    public function up(): void
    {
        Schema::table('user_tokens', function (Blueprint $table) {
            $table->string('app', 20)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('user_tokens', function (Blueprint $table) {
            $table->dropColumn('app');
        });
    }
};
