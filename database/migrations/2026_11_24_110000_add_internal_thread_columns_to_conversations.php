<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A third inbox channel, "internal": a branch team's own threads, not
     * tied to a customer. booking_id stays the heads-up link (unique, one
     * thread per booking); an internal thread may mention a booking, and
     * many threads may mention the same one, so that is its own column.
     * last_sent_by is who posted last, so the author's own post is never
     * "unread" for them. Both nullable: existing rows are untouched.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('linked_booking_id')->nullable()->after('booking_id')->constrained('bookings')->nullOnDelete();
            $table->foreignId('last_sent_by')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_booking_id');
            $table->dropConstrainedForeignId('last_sent_by');
        });
    }
};
