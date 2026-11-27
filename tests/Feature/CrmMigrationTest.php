<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\ReferralSetting;
use App\Models\Service;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Customers without Google, referral and warranty are additive: existing
 * guests, bookings and services keep everything they had.
 */
class CrmMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_guests_keep_their_data_and_only_gain_a_home_branch_and_phone_key(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_11_22_000001_add_crm_fields_to_guests.php'])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('guests', 'phone_key'));

        $now = now()->toDateTimeString();
        $cainta = Branch::factory()->create()->id;
        $other = Branch::factory()->create()->id;
        $guest = DB::table('guests')->insertGetId(['google_id' => 'g-1', 'name' => 'Maria Santos', 'email' => 'maria@example.com', 'phone' => '+63 917 123 4567', 'created_at' => $now, 'updated_at' => $now]);
        $without = DB::table('guests')->insertGetId(['google_id' => 'g-2', 'name' => 'No Bookings', 'email' => 'none@example.com', 'created_at' => $now, 'updated_at' => $now]);

        foreach ([$cainta, $other] as $index => $branch) {
            DB::table('bookings')->insert(['reference' => "826-OLD{$index}", 'access_key' => 'key', 'status' => 'confirmed', 'branch_id' => $branch, 'guest_id' => $guest, 'name' => 'Maria', 'phone' => '0917', 'created_at' => $now, 'updated_at' => $now]);
        }

        // SQLite rebuilds a table to make a column nullable; a real migrate runs outside a transaction, with foreign keys off
        Schema::withoutForeignKeyConstraints(fn () => $this->artisan('migrate')->assertSuccessful()->run());

        $maria = Guest::findOrFail($guest);
        $this->assertSame(['g-1', 'Maria Santos', 'maria@example.com', '+63 917 123 4567', 'google'], [$maria->google_id, $maria->name, $maria->email, $maria->phone, $maria->source]);
        $this->assertSame([$cainta, '9171234567', null, null], [$maria->branch_id, $maria->phone_key, $maria->referral_code, $maria->sales_rep_id]);
        $this->assertSame([null, null], [Guest::findOrFail($without)->branch_id, Guest::findOrFail($without)->phone_key]);
        $this->assertSame(2, DB::table('bookings')->where('guest_id', $guest)->count());
    }

    public function test_google_id_and_email_may_be_empty_and_google_id_stays_unique(): void
    {
        Guest::factory()->walkIn()->count(2)->create();
        $this->assertSame(2, Guest::whereNull('google_id')->whereNull('email')->count());

        Guest::factory()->create(['google_id' => 'same']);
        $this->expectException(UniqueConstraintViolationException::class);
        Guest::factory()->create(['google_id' => 'same']);
    }

    public function test_services_start_without_a_warranty_and_referral_starts_off_and_blank(): void
    {
        $service = Service::factory()->create();

        $this->assertNull($service->fresh()->warranty_months);
        $settings = ReferralSetting::instance();
        $this->assertSame([false, 'fixed', null, null], [$settings->enabled, $settings->discount_type, $settings->discount_value, $settings->owner_points]);
        $this->assertSame(0, DB::table('warranty_backjob_types')->count());
        $this->assertSame(0, DB::table('referral_redemptions')->count());
    }
}
