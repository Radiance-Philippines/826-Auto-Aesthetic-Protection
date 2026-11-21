<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\PaymentSetting;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The move from bays and drop-off times to a daily cap and drop-off dates
 * keeps what the old rows said.
 */
class BookingCoreMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_rows_keep_their_dates_statuses_and_settings(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_11_21_154222_create_vehicles_and_date_only_booking.php'])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('bookings', 'starts_at'));
        $this->assertFalse(Schema::hasColumn('bookings', 'drop_off_on'));

        $now = now()->toDateTimeString();
        $category = DB::table('service_categories')->insertGetId(['name' => 'Coating', 'slug' => 'coating', 'created_at' => $now, 'updated_at' => $now]);
        $size = DB::table('vehicle_sizes')->insertGetId(['name' => 'Sedan', 'created_at' => $now, 'updated_at' => $now]);
        $days = DB::table('services')->insertGetId(['service_category_id' => $category, 'name' => 'Graphene', 'slug' => 'graphene', 'created_at' => $now, 'updated_at' => $now]);
        $hours = DB::table('services')->insertGetId(['service_category_id' => $category, 'name' => 'Detail', 'slug' => 'detail', 'created_at' => $now, 'updated_at' => $now]);
        $none = DB::table('services')->insertGetId(['service_category_id' => $category, 'name' => 'Tint', 'slug' => 'tint', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('service_rates')->insert([
            ['service_id' => $days, 'vehicle_size_id' => $size, 'price' => 18000, 'duration_days' => 3, 'duration_minutes' => null, 'created_at' => $now, 'updated_at' => $now],
            ['service_id' => $hours, 'vehicle_size_id' => $size, 'price' => 2500, 'duration_days' => null, 'duration_minutes' => 180, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $branch = DB::table('branches')->insertGetId(['name' => 'Cainta', 'slug' => 'cainta', 'bays' => 4, 'lead_hours' => 30, 'step_minutes' => 60, 'created_at' => $now, 'updated_at' => $now]);
        $empty = DB::table('branches')->insertGetId(['name' => 'Draft', 'slug' => 'draft', 'bays' => 0, 'lead_hours' => 2, 'created_at' => $now, 'updated_at' => $now]);

        foreach ([
            ['confirmed', '2026-11-17 09:00:00', '2026-11-19 18:00:00', 5000],
            ['checked_in', '2026-11-17 09:00:00', '2026-11-17 12:00:00', null],
            ['completed', '2026-11-10 09:00:00', '2026-11-10 12:00:00', 2500],
            ['cancelled', '2026-11-18 09:00:00', '2026-11-18 12:00:00', 2500],
        ] as $index => [$status, $start, $end, $price]) {
            DB::table('bookings')->insert([
                'reference' => "826-OLD{$index}", 'access_key' => 'key', 'status' => $status, 'branch_id' => $branch,
                'starts_at' => $start, 'ends_at' => $end, 'name' => 'Old Customer', 'phone' => '0917 000 0000',
                'total_price' => $price, 'total_minutes' => 180, 'vehicle_size_id' => $price ? $size : null,
                'checked_in_at' => $status === 'checked_in' ? '2026-11-17 09:05:00' : null,
                'completed_at' => $status === 'completed' ? '2026-11-10 12:10:00' : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('branch_blocks')->insert([
            ['branch_id' => $branch, 'kind' => 'bays', 'bays' => null, 'starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2026-12-02 00:00:00', 'title' => 'All bays out', 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $branch, 'kind' => 'bays', 'bays' => 1, 'starts_at' => '2026-12-03 00:00:00', 'ends_at' => '2026-12-04 00:00:00', 'title' => 'One bay out', 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $branch, 'kind' => 'closed', 'bays' => null, 'starts_at' => '2026-12-25 00:00:00', 'ends_at' => '2026-12-26 00:00:00', 'title' => 'Christmas', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('payment_settings')->delete();
        DB::table('payment_settings')->insert(['amount_rule' => 'percent', 'amount_value' => 20, 'reservation_fee_enabled' => true, 'created_at' => $now, 'updated_at' => $now]);

        $this->artisan('migrate')->assertSuccessful();

        // Dates come from the old times; checked in and completed are arrived and exited
        $this->assertFalse(Schema::hasColumn('bookings', 'starts_at'));
        $this->assertFalse(Schema::hasColumn('bookings', 'total_minutes'));
        $bookings = Booking::query()->orderBy('id')->get();
        $this->assertSame(['confirmed', 'arrived', 'exited', 'cancelled'], $bookings->pluck('status')->all());
        $this->assertSame(['2026-11-17', '2026-11-19'], [$bookings[0]->drop_off_on->toDateString(), $bookings[0]->ready_on->toDateString()]);
        $this->assertSame('2026-11-17 09:05', $bookings[1]->arrived_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-10 12:10', $bookings[2]->exited_at->format('Y-m-d H:i'));

        // A price that was already known counts as reviewed; one that wasn't waits for review
        $this->assertTrue($bookings[0]->priceForCustomer()['status'] === 'set' && ! $bookings[0]->needsSizeReview());
        $this->assertTrue($bookings[1]->needsSizeReview());

        // The old durations became the service's estimate, and the old bays the starting cap
        $this->assertSame([3, 1, null], [Service::find($days)->estimate_days, Service::find($hours)->estimate_days, Service::find($none)->estimate_days]);
        $this->assertSame([4, 2, 0, 1], [Branch::find($branch)->daily_cap, Branch::find($branch)->lead_days, Branch::find($empty)->daily_cap, Branch::find($empty)->lead_days]);

        // All bays out is a fully booked day; a few bays out is dropped; closures stay
        $this->assertEqualsCanonicalizing(['full', 'closed'], BranchBlock::pluck('kind')->all());
        $this->assertFalse(Schema::hasColumn('branch_blocks', 'bays'));

        // Only a fixed amount is left, and it has no amount until the owner sets one
        $payments = PaymentSetting::instance();
        $this->assertSame(['fixed', null], [$payments->amount_rule, $payments->amount_value]);
        $this->assertSame(0, $payments->feeAmount());
    }
}
