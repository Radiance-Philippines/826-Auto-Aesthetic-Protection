<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\BackjobType;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\GuestToken;
use App\Models\ReferralRedemption;
use App\Models\ReferralSetting;
use App\Models\RewardRedemption;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsShop;
use Tests\TestCase;

/**
 * The customer app's read endpoints. Each one pins its response shape,
 * because the app is written against it.
 */
class AppEndpointsTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private Guest $maria;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop();

        ReferralSetting::instance()->update(['enabled' => true, 'discount_type' => 'fixed', 'discount_value' => 500, 'owner_points' => 100, 'peso_per_point' => 10]);

        $this->maria = Guest::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@example.com', 'referral_code' => 'MARIA-7K3Q']);
        $this->headers = ['Authorization' => 'Bearer '.GuestToken::issue($this->maria, 'Test phone')];
    }

    private function unit(array $overrides = []): Vehicle
    {
        return Vehicle::factory()->create(['guest_id' => $this->maria->id, 'year' => 2021, 'model' => 'Toyota Vios', 'type' => 'sedan', 'plate_number' => 'ABC1234', ...$overrides]);
    }

    private function booking(string $status, Vehicle $unit, array $overrides = []): Booking
    {
        $booking = Booking::factory()->status($status)->priced(2500)->create([
            'guest_id' => $this->maria->id,
            'vehicle_id' => $unit->id,
            'branch_id' => $this->branch->id,
            'vehicle_year' => $unit->year,
            'vehicle_model' => $unit->model,
            'vehicle_type' => $unit->type,
            'plate_number' => $unit->plate_number,
            'drop_off_on' => '2026-11-16',
            'ready_on' => '2026-11-18',
            ...$overrides,
        ]);
        $booking->items()->create(['service_id' => $this->detail->id, 'service_name' => 'Exterior detailing', 'category_name' => 'Detailing', 'price' => 2500, 'duration_days' => 1, 'warranty_months' => 12]);

        return $booking;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $array
     */
    private function assertKeys(array $keys, array $array, string $what): void
    {
        $actual = array_keys($array);
        sort($actual);
        sort($keys);
        $this->assertSame($keys, $actual, $what);
    }

    // Home

    public function test_home_shape_with_a_unit_in_the_shop_and_a_booking_ahead(): void
    {
        $vios = $this->unit();
        $city = $this->unit(['model' => 'Honda City', 'plate_number' => 'XYZ9876']);
        $inShop = $this->booking('in_progress', $vios, ['arrived_at' => now()]);
        $ahead = $this->booking('confirmed', $city, ['drop_off_on' => '2026-11-24', 'ready_on' => '2026-11-24']);
        $this->booking('exited', $vios, ['exited_at' => now()->subDays(20), 'drop_off_on' => '2026-10-20']);
        $this->booking('cancelled', $city);

        $home = $this->getJson('/api/app/home', $this->headers)->assertOk()->json();

        $this->assertKeys(['guest', 'inShop', 'nextBooking', 'referral', 'garage', 'unread'], $home, 'home');
        $this->assertKeys(['id', 'name', 'email', 'phone', 'avatar', 'referralCode', 'source'], $home['guest'], 'guest');
        $this->assertSame($inShop->id, $home['inShop']['id']);
        $this->assertSame('in_progress', $home['inShop']['stage']);
        $this->assertSame('2026-11-18', $home['inShop']['readyOn']);
        $this->assertSame('Cainta', $home['inShop']['branch']['name']);
        $this->assertSame('Toyota Vios', $home['inShop']['vehicle']['model']);
        $this->assertSame('set', $home['inShop']['price']['status']);
        $this->assertSame($ahead->id, $home['nextBooking']['id']);
        $this->assertSame(['enabled' => true, 'code' => 'MARIA-7K3Q', 'discountLabel' => '₱500 off'], $home['referral']);
        $this->assertCount(2, $home['garage']);
        $this->assertKeys(['id', 'year', 'model', 'type', 'plate', 'sizeReviewed'], $home['garage'][0], 'garage card');
        $this->assertSame(0, $home['unread']);

        // The booking shape is the one /api/my-bookings sends
        $this->assertSame($this->getJson('/api/my-bookings/'.$inShop->id, $this->headers)->json('booking'), $home['inShop']);
    }

    public function test_home_for_a_new_customer_is_empty_and_makes_the_referral_code(): void
    {
        $maria = Guest::factory()->create(['referral_code' => null]);

        $home = $this->getJson('/api/app/home', ['Authorization' => 'Bearer '.GuestToken::issue($maria, null)])->assertOk()->json();

        $this->assertNull($home['inShop']);
        $this->assertNull($home['nextBooking']);
        $this->assertSame([], $home['garage']);
        $this->assertSame($maria->refresh()->referral_code, $home['referral']['code']);
        $this->assertNotNull($home['referral']['code']);
    }

    public function test_home_shows_no_code_when_referral_is_off_and_caps_the_garage_at_six(): void
    {
        ReferralSetting::instance()->update(['enabled' => false]);
        $this->maria->update(['referral_code' => null]);

        foreach (range(1, 8) as $i) {
            $this->unit(['plate_number' => "PLT{$i}"]);
        }

        $home = $this->getJson('/api/app/home', $this->headers)->assertOk()->json();

        $this->assertSame(['enabled' => false, 'code' => null, 'discountLabel' => '₱500 off'], $home['referral']);
        $this->assertCount(6, $home['garage']);
    }

    public function test_an_expired_hold_is_not_the_next_booking_and_another_customers_booking_is_never_shown(): void
    {
        $unit = $this->unit();
        $this->booking('awaiting_payment', $unit, ['hold_expires_at' => now()->subMinute(), 'amount_due' => 500]);
        $other = Guest::factory()->create();
        Booking::factory()->status('arrived')->create(['guest_id' => $other->id, 'branch_id' => $this->branch->id]);

        $home = $this->getJson('/api/app/home', $this->headers)->assertOk()->json();

        $this->assertNull($home['nextBooking']);
        $this->assertNull($home['inShop']);
    }

    // Catalog

    public function test_catalog_shape(): void
    {
        SiteSetting::instance()->update(['google_android_client_id' => 'android.apps.googleusercontent.com']);
        $coming = Branch::factory()->create(['name' => 'Soon', 'slug' => 'soon', 'status' => 'coming_soon']);

        $catalog = $this->getJson('/api/app/catalog')->assertOk()->json();

        $this->assertKeys(['signIn', 'branches', 'categories', 'vehicleTypes', 'booking'], $catalog, 'catalog');
        $this->assertSame(['google' => true, 'apple' => false, 'demo' => false], $catalog['signIn']);

        $this->assertSame(['Cainta'], array_column($catalog['branches'], 'name'), 'only open branches');
        $this->assertKeys(['id', 'slug', 'name', 'status', 'city', 'address', 'phone', 'phoneHref', 'messenger', 'facebook', 'map', 'hours', 'lastDay', 'bookable', 'serviceIds', 'homeService'], $catalog['branches'][0], 'branch');
        $this->assertCount(7, $catalog['branches'][0]['hours']);
        $this->assertKeys(['day', 'label', 'open', 'close'], $catalog['branches'][0]['hours'][0], 'hours row');
        $this->assertTrue($catalog['branches'][0]['bookable']);

        $this->assertSame(['Detailing', 'Coating'], array_column($catalog['categories'], 'name'));
        $this->assertKeys(['id', 'name', 'slug', 'description', 'services'], $catalog['categories'][0], 'category');
        $this->assertKeys(['id', 'name', 'slug', 'summary', 'description', 'inclusions', 'image', 'homeServiceOk', 'estimateDays'], $catalog['categories'][0]['services'][0], 'service');
        $this->assertSame(1, $catalog['categories'][0]['services'][0]['estimateDays']);
        $this->assertStringNotContainsString('price', json_encode($catalog));

        $this->assertSame(['key' => 'sedan', 'label' => 'Sedan'], $catalog['vehicleTypes'][0]);
        $this->assertKeys(['cancelCutoffHours', 'rescheduleEnabled', 'rescheduleCutoffHours', 'maxReschedules', 'policy', 'fee', 'windows', 'referral'], $catalog['booking'], 'booking rules');
        $this->assertSame(['enabled' => true, 'discountLabel' => '₱500 off'], $catalog['booking']['referral']);
        $this->assertKeys(['enabled', 'label', 'holdMinutes'], $catalog['booking']['fee'], 'fee');
        $this->assertStringNotContainsString($coming->name, json_encode($catalog['branches']));
    }

    public function test_catalog_hides_sign_in_providers_that_are_not_set_up(): void
    {
        SiteSetting::instance()->update(['google_client_id' => null, 'google_client_secret' => null]);
        $this->getJson('/api/app/catalog')->assertJsonPath('signIn', ['google' => false, 'apple' => false, 'demo' => false]);

        SiteSetting::instance()->update(['apple_client_id' => 'ph.radianceph.aap826', 'google_ios_client_id' => 'ios.apps.googleusercontent.com']);
        config(['app.demo' => true]);
        $this->getJson('/api/app/catalog')->assertJsonPath('signIn', ['google' => true, 'apple' => true, 'demo' => true]);
    }

    // Latest release

    public function test_latest_release_shape(): void
    {
        $this->getJson('/api/app/latest')->assertOk()->assertExactJson(['version' => null, 'versionCode' => 0, 'url' => null, 'sha256' => null, 'notes' => null, 'mandatory' => false]);

        AppRelease::factory()->create(['version' => '0.2.0', 'version_code' => 2, 'sha256' => str_repeat('b', 64), 'notes' => 'Fixes']);
        AppRelease::factory()->create(['version' => '0.1.0', 'version_code' => 1]);
        AppRelease::factory()->draft()->create(['version' => '0.3.0', 'version_code' => 3]);
        AppRelease::factory()->create(['version' => '0.2.5', 'version_code' => 25, 'sha256' => str_repeat('a', 64), 'notes' => "Faster\nSafer", 'mandatory' => true]);

        $this->getJson('/api/app/latest')->assertOk()->assertExactJson([
            'version' => '0.2.5',
            'versionCode' => 25,
            'url' => url('/download/826-auto-0.2.5.apk'),
            'sha256' => str_repeat('a', 64),
            'notes' => "Faster\nSafer",
            'mandatory' => true,
        ]);
    }

    // Rewards

    public function test_rewards_shape_and_privacy(): void
    {
        $juan = Guest::factory()->create(['name' => 'Juan Dela Cruz', 'email' => 'juan@example.com', 'phone' => '0917 111 2222']);
        $ana = Guest::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $pedro = Guest::factory()->create(['name' => 'Pedro Reyes']);
        $other = Guest::factory()->create();

        $awarded = Booking::factory()->status('exited')->create(['guest_id' => $juan->id, 'branch_id' => $this->branch->id, 'referral_guest_id' => $this->maria->id]);
        $pending = Booking::factory()->create(['guest_id' => $ana->id, 'branch_id' => $this->branch->id, 'referral_guest_id' => $this->maria->id]);
        $voided = Booking::factory()->status('cancelled')->create(['guest_id' => $pedro->id, 'branch_id' => $this->branch->id, 'referral_guest_id' => $this->maria->id]);
        $notMine = Booking::factory()->create(['guest_id' => $other->id, 'branch_id' => $this->branch->id, 'referral_guest_id' => $other->id]);

        $redemption = fn (Guest $friend, Booking $booking, array $extra = []) => ReferralRedemption::create(['code_guest_id' => $this->maria->id, 'used_by_guest_id' => $friend->id, 'booking_id' => $booking->id, ...$extra]);
        $redemption($juan, $awarded, ['points_awarded' => 100, 'awarded_at' => '2026-11-10 10:00:00']);
        $redemption($ana, $pending);
        $redemption($pedro, $voided, ['voided_at' => '2026-11-12 10:00:00']);
        ReferralRedemption::create(['code_guest_id' => $other->id, 'used_by_guest_id' => $juan->id, 'booking_id' => $notMine->id, 'points_awarded' => 999, 'awarded_at' => now()]);

        $booked = Booking::factory()->create(['guest_id' => $this->maria->id, 'branch_id' => $this->branch->id, 'reference' => '826-ABCDE']);
        RewardRedemption::create(['guest_id' => $this->maria->id, 'points' => 30, 'peso_value' => 300, 'booking_id' => $booked->id])->forceFill(['created_at' => now()->subHour()])->save();
        RewardRedemption::create(['guest_id' => $this->maria->id, 'points' => 5, 'note' => 'Free air freshener']);

        $json = $this->getJson('/api/my-rewards', $this->headers)->assertOk()->assertExactJson([
            'enabled' => true,
            'code' => 'MARIA-7K3Q',
            'discountLabel' => '₱500 off',
            'pointsBalance' => 65,
            'pointsEarned' => 100,
            'pointsSpent' => 35,
            'pesoPerPoint' => 10,
            'friends' => [
                ['name' => 'Pedro R.', 'usedOn' => '2026-11-16', 'status' => 'voided', 'points' => 0],
                ['name' => 'Ana', 'usedOn' => '2026-11-16', 'status' => 'pending', 'points' => 0],
                ['name' => 'Juan C.', 'usedOn' => '2026-11-16', 'status' => 'awarded', 'points' => 100],
            ],
            'ledger' => [
                ['date' => '2026-11-16', 'kind' => 'adjusted', 'points' => -5, 'note' => 'Adjusted by the shop: Free air freshener'],
                ['date' => '2026-11-16', 'kind' => 'spent', 'points' => -30, 'note' => 'Redeemed on 826-ABCDE'],
                ['date' => '2026-11-10', 'kind' => 'earned', 'points' => 100, 'note' => 'Juan C. used your code'],
            ],
        ])->getContent();

        foreach (['Dela Cruz', 'Reyes', 'Juan Cruz', 'juan@example.com', '0917 111 2222', 'ana@example.com'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_rewards_when_nothing_is_set_up_or_earned(): void
    {
        ReferralSetting::instance()->update(['enabled' => false, 'peso_per_point' => null]);
        $this->maria->update(['referral_code' => null]);

        $this->getJson('/api/my-rewards', $this->headers)->assertOk()->assertExactJson([
            'enabled' => false,
            'code' => null,
            'discountLabel' => '₱500 off',
            'pointsBalance' => 0,
            'pointsEarned' => 0,
            'pointsSpent' => 0,
            'pesoPerPoint' => null,
            'friends' => [],
            'ledger' => [],
        ]);
    }

    public function test_rewards_warranty_and_unit_history_need_a_sign_in(): void
    {
        foreach (['/api/my-rewards', '/api/my-warranty', '/api/my-vehicles/1'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    // One unit

    public function test_a_unit_with_its_history_and_never_another_customers(): void
    {
        $vios = $this->unit();
        $city = $this->unit(['model' => 'Honda City', 'plate_number' => 'XYZ9876']);
        $done = $this->booking('exited', $vios, ['drop_off_on' => '2026-10-20', 'ready_on' => '2026-10-21', 'reference' => '826-AAAAA']);
        $ahead = $this->booking('confirmed', $vios, ['drop_off_on' => '2026-11-24', 'ready_on' => '2026-11-24', 'reference' => '826-BBBBB', 'priced_at' => null, 'total_price' => null]);
        $this->booking('confirmed', $city);

        $this->getJson("/api/my-vehicles/{$vios->id}", $this->headers)->assertOk()->assertExactJson([
            'vehicle' => ['id' => $vios->id, 'year' => 2021, 'model' => 'Toyota Vios', 'type' => 'sedan', 'plate' => 'ABC1234', 'sizeReviewed' => false],
            'history' => [
                ['id' => $ahead->id, 'reference' => '826-BBBBB', 'date' => '2026-11-24', 'branch' => 'Cainta', 'services' => ['Exterior detailing'], 'status' => 'confirmed', 'price' => null],
                ['id' => $done->id, 'reference' => '826-AAAAA', 'date' => '2026-10-20', 'branch' => 'Cainta', 'services' => ['Exterior detailing'], 'status' => 'exited', 'price' => 2500],
            ],
        ]);

        $stranger = Vehicle::factory()->create(['guest_id' => Guest::factory()->create()->id]);
        $this->getJson("/api/my-vehicles/{$stranger->id}", $this->headers)->assertNotFound();
        $this->getJson('/api/my-vehicles/9999', $this->headers)->assertNotFound();
    }

    // Warranty

    public function test_warranty_is_built_from_finished_bookings_with_the_existing_math(): void
    {
        $type = BackjobType::create(['name' => 'Coating peeling', 'sort_order' => 1]);
        BackjobType::create(['name' => 'Hidden type', 'sort_order' => 2, 'is_active' => false]);
        $this->coating->backjobTypes()->sync([$type->id, BackjobType::where('name', 'Hidden type')->value('id')]);

        $vios = $this->unit();
        $city = $this->unit(['model' => 'Honda City', 'plate_number' => 'XYZ9876']);

        // Jan 31 plus 1 month ends on the last day of February
        $recent = $this->booking('exited', $vios, ['exited_at' => '2026-10-31 12:00:00']);
        $recent->items()->delete();
        $recent->items()->createMany([
            ['service_id' => $this->coating->id, 'service_name' => 'Graphene coating', 'warranty_months' => 12, 'duration_days' => 3],
            ['service_id' => $this->detail->id, 'service_name' => 'Exterior detailing', 'warranty_months' => null, 'duration_days' => 1],
        ]);
        $old = $this->booking('exited', $city, ['exited_at' => '2025-10-01 12:00:00', 'drop_off_on' => '2025-09-30']);
        $old->items()->update(['warranty_months' => 6]);

        // Not finished, or somebody else's: never listed
        $this->booking('in_progress', $vios);
        Booking::factory()->status('exited')->create(['guest_id' => Guest::factory()->create()->id, 'branch_id' => $this->branch->id, 'exited_at' => now()]);

        $this->getJson('/api/my-warranty', $this->headers)->assertOk()->assertExactJson([
            [
                'vehicleId' => $vios->id,
                'vehicleLabel' => '2021 Toyota Vios (Sedan) ABC1234',
                'services' => [
                    ['name' => 'Graphene coating', 'finishedOn' => '2026-10-31', 'endsOn' => '2027-10-31', 'status' => 'active', 'months' => 12, 'backjobTypesCovered' => ['Coating peeling']],
                    ['name' => 'Exterior detailing', 'finishedOn' => '2026-10-31', 'endsOn' => null, 'status' => 'none', 'months' => null, 'backjobTypesCovered' => []],
                ],
            ],
            [
                'vehicleId' => $city->id,
                'vehicleLabel' => '2021 Honda City (Sedan) XYZ9876',
                'services' => [
                    ['name' => 'Exterior detailing', 'finishedOn' => '2025-10-01', 'endsOn' => '2026-04-01', 'status' => 'expired', 'months' => 6, 'backjobTypesCovered' => []],
                ],
            ],
        ]);
    }

    public function test_warranty_is_an_empty_list_with_nothing_finished(): void
    {
        $this->getJson('/api/my-warranty', $this->headers)->assertOk()->assertExactJson([]);
    }

    // The old endpoints are unchanged for the site

    public function test_other_customers_bookings_stay_hidden_from_a_token(): void
    {
        $stranger = Booking::factory()->create(['guest_id' => Guest::factory()->create()->id, 'branch_id' => $this->branch->id]);

        $this->getJson("/api/my-bookings/{$stranger->id}", $this->headers)->assertNotFound();
        $this->postJson("/api/my-bookings/{$stranger->id}/cancel", [], $this->headers)->assertNotFound();
        $this->getJson('/api/my-bookings', $this->headers)->assertJsonCount(0, 'bookings');
    }

    public function test_the_owner_of_the_site_is_not_a_customer(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->getJson('/api/app/me')->assertUnauthorized();
    }
}
