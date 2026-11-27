<?php

namespace Tests\Feature;

use App\Filament\Pages\ReferralSettings;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\ReferralRedemption;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Referrals;
use App\Support\Reservations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsShop;
use Tests\TestCase;

/**
 * Referral codes: who can use one, the discount (shown once the booking is
 * priced), the owner's points (once, when the booking exits) and voiding.
 */
class ReferralTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private Guest $owner;

    private Guest $friend;

    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop();
        $this->owner = Guest::factory()->create(['name' => 'Juan dela Cruz']);
        $this->friend = Guest::factory()->create(['name' => 'Maria Santos']);
        $this->code = Referrals::codeFor($this->owner);
        $this->terms('fixed', 500, 100);
    }

    private function terms(string $type, ?int $value, ?int $points = null, bool $enabled = true): void
    {
        ReferralSetting::instance()->update(['enabled' => $enabled, 'discount_type' => $type, 'discount_value' => $value, 'owner_points' => $points]);
    }

    private function reserve(array $overrides = [], ?Guest $as = null)
    {
        return $this->actingAs($as ?? $this->friend, 'guest')->postJson('/api/bookings', $this->reservation(['referral_code' => $this->code, ...$overrides]));
    }

    public function test_a_code_is_first_name_letters_a_dash_and_four_unambiguous_characters(): void
    {
        $this->assertMatchesRegularExpression('/^JUAN-[2-9A-HJKMNP-Z]{4}$/', $this->code);
        $this->assertSame($this->code, Referrals::codeFor($this->owner->fresh()));
        $this->assertNotSame($this->code, Referrals::codeFor($this->friend));
        $this->assertMatchesRegularExpression('/^MARIA-/', $this->friend->fresh()->referral_code);
    }

    public function test_the_discount_shows_once_the_unit_is_priced(): void
    {
        $this->reserve()->assertCreated()
            ->assertJsonPath('booking.price.status', 'reviewing')
            ->assertJsonPath('booking.price.discount', null);

        $booking = Booking::sole();
        $redemption = ReferralRedemption::sole();
        $this->assertSame($this->owner->id, $booking->referral_guest_id);
        $this->assertSame([$this->owner->id, $this->friend->id, $booking->id, null], [$redemption->code_guest_id, $redemption->used_by_guest_id, $redemption->booking_id, $redemption->discount_amount]);

        Reservations::applySize($booking, $this->medium);

        $this->assertSame([2000, 500], [$booking->fresh()->total_price, $booking->fresh()->discount_amount]);
        $this->assertSame(500, $redemption->fresh()->discount_amount);
        $this->assertSame(['status' => 'set', 'total' => 2000, 'subtotal' => 2500, 'discount' => 500, 'items' => [['name' => 'Exterior detailing', 'price' => 2500]]], $booking->fresh()->priceForCustomer());
    }

    public function test_a_reviewed_unit_gets_the_discount_at_once(): void
    {
        $vehicle = Vehicle::factory()->reviewed($this->medium)->create(['guest_id' => $this->friend->id]);

        $this->reserve(['vehicle_id' => $vehicle->id, 'vehicle_year' => null, 'vehicle_model' => null, 'vehicle_type' => null, 'plate_number' => null, 'services' => [$this->detail->id, $this->coating->id]])
            ->assertCreated()
            ->assertJsonPath('booking.price.total', 20000)
            ->assertJsonPath('booking.price.subtotal', 20500)
            ->assertJsonPath('booking.price.discount', 500);
    }

    public function test_percent_and_fixed_discounts_never_go_below_zero(): void
    {
        $this->terms('percent', 10);
        $this->reserve(['services' => [$this->coating->id]])->assertCreated();
        Reservations::applySize(Booking::sole(), $this->medium);
        $this->assertSame([16200, 1800], [Booking::sole()->total_price, Booking::sole()->discount_amount]);

        // Changing the size class re-works the discount from the new subtotal
        $this->terms('fixed', 99_999);
        Reservations::applySize(Booking::sole(), $this->medium);
        $this->assertSame([0, 18000], [Booking::sole()->total_price, Booking::sole()->discount_amount]);
    }

    public function test_the_code_is_checked_on_the_booking_and_the_error_names_the_field(): void
    {
        $this->reserve(['referral_code' => 'NOPE-0000'])->assertUnprocessable()->assertJsonValidationErrors(['referral_code']);
        $this->reserve([], $this->owner)->assertUnprocessable()->assertJsonPath('errors.referral_code.0', 'You can’t use your own referral code.');
        $this->assertSame(0, Booking::count());

        // A lowercase code with spaces still works
        $this->reserve(['referral_code' => '  '.strtolower($this->code).' '])->assertCreated();
        $this->assertSame(1, ReferralRedemption::count());
    }

    public function test_a_code_is_for_a_first_booking_only(): void
    {
        Booking::factory()->create(['guest_id' => $this->friend->id, 'branch_id' => $this->branch->id]);

        $this->reserve()->assertUnprocessable()->assertJsonPath('errors.referral_code.0', 'Referral codes are for your first booking only.');
        $this->assertSame(0, ReferralRedemption::count());

        // Without a code, the booking goes through as usual
        $this->reserve(['referral_code' => null])->assertCreated();
        $this->assertSame(0, ReferralRedemption::count());
    }

    public function test_an_earlier_booking_that_fell_through_does_not_count(): void
    {
        Booking::factory()->status('cancelled')->create(['guest_id' => $this->friend->id, 'branch_id' => $this->branch->id]);
        Booking::factory()->status('declined')->create(['guest_id' => $this->friend->id, 'branch_id' => $this->branch->id]);
        Booking::factory()->hold(-5)->create(['guest_id' => $this->friend->id, 'branch_id' => $this->branch->id]);

        $this->reserve()->assertCreated();
        $this->assertSame(1, ReferralRedemption::count());
    }

    public function test_a_person_uses_a_code_once_and_others_can_still_use_it(): void
    {
        $this->reserve()->assertCreated();

        $this->reserve(['date' => '2026-11-18', 'plate_number' => 'DEF 456'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.referral_code.0', 'You have already used this code.');

        $this->reserve(['date' => '2026-11-18', 'plate_number' => 'GHI 789'], Guest::factory()->create())->assertCreated();
        $this->assertSame(2, ReferralRedemption::where('code_guest_id', $this->owner->id)->count());

        // The same check where the use is written, for two requests that passed the first one together
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You have already used this code.');
        Referrals::redeem(Booking::factory()->create(['guest_id' => $this->friend->id, 'branch_id' => $this->branch->id]), $this->owner);
    }

    public function test_codes_are_refused_while_off_or_without_a_discount(): void
    {
        $this->terms('fixed', 500, 100, enabled: false);
        $this->reserve()->assertUnprocessable()->assertJsonValidationErrors(['referral_code']);

        $this->terms('fixed', null);
        $this->reserve()->assertUnprocessable()->assertJsonValidationErrors(['referral_code']);
        $this->assertSame(0, Booking::count());
    }

    public function test_the_owner_earns_the_points_once_when_the_booking_exits(): void
    {
        $this->reserve()->assertCreated();
        $booking = Booking::sole();

        $booking->update(['status' => 'arrived']);
        $this->assertSame(0, ReferralRedemption::sole()->points_awarded);

        $booking->update(['status' => 'exited']);
        $redemption = ReferralRedemption::sole();
        $this->assertSame(100, $redemption->points_awarded);
        $this->assertNotNull($redemption->awarded_at);

        // Moving it around again, or changing the points, never pays twice
        $this->terms('fixed', 500, 999);
        $booking->update(['status' => 'ready']);
        $booking->update(['status' => 'exited']);
        $this->assertSame(100, $redemption->fresh()->points_awarded);

        $this->assertSame(['usedCount' => 1, 'pointsEarned' => 100, 'pointsPending' => 0], Referrals::stats($this->owner));
    }

    public function test_a_booking_that_falls_through_voids_it_and_the_code_can_be_used_again(): void
    {
        foreach (['cancelled', 'declined', 'expired'] as $status) {
            $this->reserve()->assertCreated();
            $booking = Booking::latest('id')->first();
            $this->assertSame(['usedCount' => 1, 'pointsEarned' => 0, 'pointsPending' => 100], Referrals::stats($this->owner->fresh()));

            $booking->update(['status' => $status]);

            $this->assertSame('voided', $booking->referralRedemption->fresh()->status());
            $this->assertSame(['usedCount' => 0, 'pointsEarned' => 0, 'pointsPending' => 0], Referrals::stats($this->owner->fresh()));
        }

        $this->assertSame(3, ReferralRedemption::whereNotNull('voided_at')->count());
        $this->assertSame(0, ReferralRedemption::whereNotNull('awarded_at')->count());
    }

    public function test_my_referral_gives_the_code_and_what_it_earned(): void
    {
        $this->getJson('/api/my-referral')->assertUnauthorized();

        $this->actingAs($this->friend, 'guest')->getJson('/api/my-referral')
            ->assertOk()
            ->assertExactJson(['enabled' => true, 'code' => $this->friend->fresh()->referral_code, 'discountLabel' => '₱500 off', 'usedCount' => 0, 'pointsEarned' => 0, 'pointsPending' => 0]);

        $this->reserve()->assertCreated();
        Booking::sole()->update(['status' => 'exited']);

        $this->actingAs($this->owner, 'guest')->getJson('/api/my-referral')
            ->assertOk()
            ->assertJsonPath('code', $this->code)
            ->assertJsonPath('usedCount', 1)
            ->assertJsonPath('pointsEarned', 100);

        $this->terms('percent', 10);
        $this->actingAs($this->owner, 'guest')->getJson('/api/my-referral')->assertJsonPath('discountLabel', '10% off');

        $this->terms('percent', 10, 100, enabled: false);
        $this->actingAs($this->owner, 'guest')->getJson('/api/my-referral')->assertJsonPath('enabled', false);
    }

    public function test_a_demo_gets_a_sample_referral_card(): void
    {
        config(['app.demo' => true]);

        $this->getJson('/api/my-referral')->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('code', 'SAMPLE-7K3Q');
    }

    public function test_the_site_knows_whether_referral_is_on_and_the_discount_label(): void
    {
        $this->assertSame(['enabled' => true, 'discountLabel' => '₱500 off'], $this->get('/book')->viewData('app')['booking']['referral']);

        $this->terms('fixed', null);
        $this->assertSame(['enabled' => false, 'discountLabel' => null], $this->get('/book')->viewData('app')['booking']['referral']);
    }

    public function test_only_owners_set_the_referral_terms(): void
    {
        $staff = User::factory()->create();
        $staff->branches()->attach($this->branch);
        $this->actingAs($staff)->get($this->panel('/referral'))->assertForbidden();

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(ReferralSettings::class)
            ->fillForm(['enabled' => true, 'discount_type' => 'percent', 'discount_value' => 15, 'owner_points' => 50])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['15% off', 50], [ReferralSetting::current()->label(), ReferralSetting::current()->owner_points]);
    }

    public function test_nothing_is_set_until_the_owner_decides(): void
    {
        ReferralSetting::query()->delete();
        Cache::flush();

        $settings = ReferralSetting::current();
        $this->assertSame([false, null, null, null], [$settings->enabled, $settings->discount_value, $settings->owner_points, $settings->label()]);
    }
}
