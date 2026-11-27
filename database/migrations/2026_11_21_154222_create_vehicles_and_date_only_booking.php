<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * From bays and drop-off times to one queue per branch and a drop-off
     * date: a daily cap instead of bays, one day estimate per service, the
     * customer's unit (year, model, type, plate) kept in vehicles, and a size
     * class that the admin assigns after seeing the unit, which sets the
     * price. Old rows keep their data: dates come from the old times, the
     * old checked-in and completed statuses become arrived and exited.
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('model', 80);
            $table->string('type', 20);
            $table->string('plate_number', 15)->index();
            $table->foreignId('vehicle_size_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('size_reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['guest_id', 'plate_number']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->after('guest_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('vehicle_year')->nullable()->after('vehicle_size_name');
            $table->string('vehicle_type', 20)->nullable()->after('vehicle_model');
            $table->date('drop_off_on')->nullable()->index()->after('status');
            $table->date('ready_on')->nullable()->after('drop_off_on');
            $table->timestamp('size_reviewed_at')->nullable();
            $table->timestamp('priced_at')->nullable();
        });

        // Dates from the old times, and a price that was already known counts as reviewed
        DB::table('bookings')->orderBy('id')->each(function (object $booking) {
            DB::table('bookings')->where('id', $booking->id)->update([
                'drop_off_on' => $booking->starts_at ? substr((string) $booking->starts_at, 0, 10) : null,
                'ready_on' => $booking->ends_at ? substr((string) $booking->ends_at, 0, 10) : null,
                'size_reviewed_at' => $booking->vehicle_size_id ? $booking->created_at : null,
                'priced_at' => $booking->total_price !== null ? $booking->created_at : null,
            ]);
        });

        DB::table('bookings')->where('status', 'checked_in')->update(['status' => 'arrived']);
        DB::table('bookings')->where('status', 'completed')->update(['status' => 'exited']);

        Schema::table('bookings', function (Blueprint $table) {
            $table->renameColumn('checked_in_at', 'arrived_at');
            $table->renameColumn('completed_at', 'exited_at');
        });

        Schema::table('bookings', function (Blueprint $table) {
            // MySQL keeps an index under every foreign key: give branch_id its own before the old one goes
            $table->index('branch_id');
            $table->dropIndex(['branch_id', 'kind', 'starts_at', 'ends_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at', 'total_minutes']);
        });

        Schema::table('booking_items', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('estimate_days')->nullable()->after('home_service_ok');
        });

        // What the owner already entered as a duration becomes the service's estimate
        DB::table('service_rates')->orderBy('id')->each(function (object $rate) {
            $days = $rate->duration_days ? (int) $rate->duration_days : ($rate->duration_minutes ? 1 : 0);
            $current = (int) DB::table('services')->where('id', $rate->service_id)->value('estimate_days');

            if ($days > $current) {
                DB::table('services')->where('id', $rate->service_id)->update(['estimate_days' => $days]);
            }
        });

        Schema::table('service_rates', function (Blueprint $table) {
            $table->dropColumn(['duration_minutes', 'duration_days']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedTinyInteger('daily_cap')->default(0)->after('weekly_hours');
            $table->unsignedTinyInteger('lead_days')->default(1)->after('daily_cap');
        });

        // The old bays are the starting cap, and the notice rounds up to whole days
        DB::table('branches')->orderBy('id')->each(function (object $branch) {
            DB::table('branches')->where('id', $branch->id)->update([
                'daily_cap' => min(255, (int) $branch->bays),
                'lead_days' => max(1, min(60, (int) ceil(((int) $branch->lead_hours) / 24))),
            ]);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['bays', 'step_minutes', 'lead_hours', 'multiday_dropoff_by']);
        });

        // Every bay out is a fully booked day; a few bays out say nothing about the day, so they go
        DB::table('branch_blocks')->where('kind', 'bays')->whereNull('bays')->update(['kind' => 'full']);
        DB::table('branch_blocks')->where('kind', 'bays')->delete();

        Schema::table('branch_blocks', function (Blueprint $table) {
            $table->dropColumn('bays');
        });

        // Only a fixed amount is left; a percent or "the whole estimate" has no price to follow
        DB::table('payment_settings')->whereIn('amount_rule', ['percent', 'full'])->update(['amount_rule' => 'fixed', 'amount_value' => null]);
    }

    public function down(): void
    {
        Schema::table('branch_blocks', function (Blueprint $table) {
            $table->unsignedTinyInteger('bays')->nullable()->after('kind');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedTinyInteger('bays')->default(0);
            $table->unsignedSmallInteger('step_minutes')->default(60);
            $table->unsignedSmallInteger('lead_hours')->default(2);
            $table->string('multiday_dropoff_by', 5)->nullable();
        });

        DB::table('branches')->update(['bays' => DB::raw('daily_cap')]);

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['daily_cap', 'lead_days']);
        });

        Schema::table('service_rates', function (Blueprint $table) {
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('duration_days')->nullable();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('estimate_days');
        });

        Schema::table('booking_items', function (Blueprint $table) {
            $table->unsignedInteger('duration_minutes')->default(0);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('total_minutes')->default(0);
        });

        DB::table('bookings')->orderBy('id')->each(function (object $booking) {
            DB::table('bookings')->where('id', $booking->id)->update([
                'starts_at' => $booking->drop_off_on ? "{$booking->drop_off_on} 09:00:00" : null,
                'ends_at' => $booking->ready_on ? "{$booking->ready_on} 18:00:00" : null,
            ]);
        });

        DB::table('bookings')->where('status', 'arrived')->update(['status' => 'checked_in']);
        DB::table('bookings')->where('status', 'exited')->update(['status' => 'completed']);
        DB::table('bookings')->whereIn('status', ['in_progress', 'quality_check'])->update(['status' => 'checked_in']);

        Schema::table('bookings', function (Blueprint $table) {
            $table->renameColumn('arrived_at', 'checked_in_at');
            $table->renameColumn('exited_at', 'completed_at');
            $table->index(['branch_id', 'kind', 'starts_at', 'ends_at']);
        });

        // The index up() added for MySQL; databases migrated before it never had one
        if (Schema::hasIndex('bookings', 'bookings_branch_id_index')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropIndex('bookings_branch_id_index');
            });
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropIndex(['drop_off_on']);
            $table->dropColumn(['vehicle_year', 'vehicle_type', 'drop_off_on', 'ready_on', 'size_reviewed_at', 'priced_at']);
        });

        Schema::dropIfExists('vehicles');
    }
};
