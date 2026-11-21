<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers who never signed in with Google (walk-ins, phone customers)
     * are guests too, so google_id and email may be empty. Each customer can
     * have a home branch, a sales rep, a referral code and notes. Existing
     * rows keep everything: only the home branch (from their first booking)
     * and the phone key are filled in.
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('google_id')->nullable()->change();
            $table->string('email')->nullable()->change();
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('referral_code', 30)->nullable()->unique();
            $table->text('notes')->nullable();
            // google, walk_in, phone or admin
            $table->string('source', 20)->default('google');
            // The last 10 digits of the phone, so "0917 123 4567" and "+63 917 123 4567" are one customer
            $table->string('phone_key', 10)->nullable()->index();
        });

        DB::table('guests')->orderBy('id')->each(function (object $guest) {
            $branch = DB::table('bookings')->where('guest_id', $guest->id)->orderBy('id')->value('branch_id');
            $digits = preg_replace('/\D/', '', (string) $guest->phone);

            DB::table('guests')->where('id', $guest->id)->update([
                'branch_id' => $branch,
                'phone_key' => strlen($digits) >= 10 ? substr($digits, -10) : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('sales_rep_id');
            $table->dropUnique(['referral_code']);
            $table->dropIndex(['phone_key']);
            $table->dropColumn(['referral_code', 'notes', 'source', 'phone_key']);
        });
    }
};
