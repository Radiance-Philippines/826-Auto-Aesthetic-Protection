<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * App releases now belong to one of the two apps: "customer" (what
     * every existing row is) or "staff" (826 Team). Version codes only
     * need to be unique inside one app.
     */
    public function up(): void
    {
        Schema::table('app_releases', function (Blueprint $table) {
            $table->string('app', 20)->default('customer')->after('id');
            $table->dropUnique(['version_code']);
            $table->unique(['app', 'version_code']);
        });
    }

    public function down(): void
    {
        Schema::table('app_releases', function (Blueprint $table) {
            $table->dropUnique(['app', 'version_code']);
            $table->unique('version_code');
            $table->dropColumn('app');
        });
    }
};
