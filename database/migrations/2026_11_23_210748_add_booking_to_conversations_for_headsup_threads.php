<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A second inbox channel, "headsup": one thread per booking where
     * operations tells sales about a changed ready date or unit. Messenger
     * rows keep booking_id null (many nulls are allowed under the unique).
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->unique()->after('guest_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropUnique(['booking_id']);
            $table->dropColumn('booking_id');
        });
    }
};
