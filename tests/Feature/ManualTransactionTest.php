<?php

namespace Tests\Feature;

use App\Filament\Pages\ManualTransactionPage;
use App\Mail\CustomerBookingUpdate;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\MailSetting;
use App\Models\ReferralRedemption;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Support\Referrals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\BuildsShop;
use Tests\TestCase;

/**
 * Jobs done outside the site (walk-in, phone, even finished), entered by
 * the team: totals, payment, customer and no emails.
 */
class ManualTransactionTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop(cap: 1);
        $this->staff = User::factory()->create();
        $this->staff->branches()->attach($this->branch);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'branch_id' => $this->branch->id,
            'new_customer' => true,
            'name' => 'Pedro Reyes',
            'phone' => '0922 555 0000',
            'service_date' => '2026-11-10',
            'status' => 'exited',
            'vehicle_year' => 2019,
            'vehicle_model' => 'Honda City',
            'vehicle_type' => 'sedan',
            'plate_number' => 'xyz 9999',
            'vehicle_size_id' => $this->medium->id,
            'services' => [$this->detail->id, $this->coating->id],
            ...$overrides,
        ];
    }

    public function test_the_page_prices_each_service_from_the_rates_and_saves_what_the_team_typed(): void
    {
        $this->actingAs($this->staff);

        Livewire::test(ManualTransactionPage::class)
            ->fillForm($this->form())
            ->assertFormSet(['prices.'.$this->detail->id => 2500, 'prices.'.$this->coating->id => 18000])
            ->fillForm(['prices' => [$this->detail->id => 2000, $this->coating->id => 17000], 'discount' => 500, 'amount_paid' => 18500, 'payment_method' => 'gcash', 'payment_reference' => 'ref 123', 'admin_note' => 'Paid in full'])
            ->call('save')
            ->assertHasNoFormErrors();

        $booking = Booking::sole();
        $this->assertSame(['manual', 'exited', 'onsite', 18500, 500, 18500], [$booking->source, $booking->status, $booking->kind, $booking->total_price, $booking->discount_amount, $booking->amount_paid]);
        $this->assertSame([2000, 17000], $booking->items->pluck('price')->all());
        $this->assertSame(['gcash', 'ref 123', 'Paid in full'], [$booking->payment_method, $booking->payment_note, $booking->admin_note]);
        $this->assertSame(['2026-11-10', '2026-11-10', '2026-11-10'], [$booking->drop_off_on->toDateString(), $booking->ready_on->toDateString(), $booking->exited_at->toDateString()]);
        $this->assertNotNull($booking->paid_at);
        $this->assertNotNull($booking->size_reviewed_at);
        $this->assertNotNull($booking->priced_at);
        $this->assertSame('Medium', $booking->vehicle_size_name);
        $this->assertFalse($booking->needsSizeReview());
        $this->assertSame('Manual entry', Booking::SOURCES[$booking->source]);

        $guest = Guest::sole();
        $this->assertSame([$guest->id, 'walk_in', null, $this->branch->id], [$booking->guest_id, $guest->source, $guest->google_id, $guest->branch_id]);
        $this->assertSame('XYZ9999', $guest->vehicles()->sole()->plate_number);
        $this->assertSame($guest->vehicles()->sole()->id, $booking->vehicle_id);
        $this->assertTrue($guest->vehicles()->sole()->isReviewed());

        Mail::assertNothingSent();
    }

    public function test_unpaid_has_no_paid_date_and_a_job_in_progress_is_not_exited(): void
    {
        $this->actingAs($this->staff);

        Livewire::test(ManualTransactionPage::class)
            ->fillForm($this->form(['status' => 'in_progress', 'services' => [$this->detail->id]]))
            ->call('save')
            ->assertHasNoFormErrors();

        $booking = Booking::sole();
        $this->assertSame(['in_progress', 2500, null, null, null], [$booking->status, $booking->total_price, $booking->paid_at, $booking->amount_paid, $booking->exited_at]);
        $this->assertNotNull($booking->arrived_at);
        $this->assertNull($booking->discount_amount);
    }

    public function test_the_payment_method_is_needed_once_something_was_paid(): void
    {
        $this->actingAs($this->staff);

        Livewire::test(ManualTransactionPage::class)
            ->fillForm($this->form(['services' => [$this->detail->id], 'amount_paid' => 100]))
            ->call('save')
            ->assertHasFormErrors(['payment_method' => 'required']);

        $this->assertSame(0, Booking::count());
    }

    public function test_an_existing_customer_is_picked_and_the_day_s_cap_is_not_checked(): void
    {
        $this->actingAs($this->staff);
        Booking::factory()->on('2026-11-17')->create(['branch_id' => $this->branch->id]);
        $guest = Guest::factory()->create(['name' => 'Maria Santos', 'phone' => '0917 111 2222', 'branch_id' => $this->branch->id]);

        // The branch's cap is 1 and that day is full; a manual entry is not stopped, even for a past date
        foreach (['2026-11-17', '2026-10-01'] as $date) {
            Livewire::test(ManualTransactionPage::class)
                ->fillForm($this->form(['new_customer' => false, 'guest_id' => $guest->id, 'service_date' => $date, 'services' => [$this->detail->id]]))
                ->call('save')
                ->assertHasNoFormErrors();
        }

        $this->assertSame(2, Booking::where('source', 'manual')->where('guest_id', $guest->id)->count());
        $this->assertSame('Maria Santos', Booking::where('source', 'manual')->first()->name);
        // Both entries are for the same unit, so it is reused
        $this->assertSame(1, $guest->vehicles()->count());
    }

    public function test_a_new_customer_with_a_known_phone_number_is_not_made_twice(): void
    {
        $this->actingAs($this->staff);
        Guest::factory()->walkIn()->create(['phone' => '0922 555 0000', 'name' => 'Pedro Reyes']);

        Livewire::test(ManualTransactionPage::class)->fillForm($this->form(['services' => [$this->detail->id], 'phone' => '+63 922 555 0000']))->call('save')->assertHasNoFormErrors();

        $this->assertSame(1, Guest::count());
    }

    public function test_staff_cannot_use_another_branch(): void
    {
        $other = Branch::factory()->create();
        $this->actingAs($this->staff);

        Livewire::test(ManualTransactionPage::class)
            ->fillForm($this->form(['branch_id' => $other->id, 'services' => []]))
            ->call('save')
            ->assertHasFormErrors();

        $this->assertSame(0, Booking::count());
        Mail::assertNotSent(CustomerBookingUpdate::class);
    }

    public function test_the_manual_entry_shows_as_manual_on_the_booking_page(): void
    {
        $this->actingAs($this->staff);

        Livewire::test(ManualTransactionPage::class)->fillForm($this->form(['services' => [$this->detail->id], 'amount_paid' => 2500, 'payment_method' => 'cash']))->call('save');

        $this->get('/admin/bookings/'.Booking::sole()->id)->assertOk()->assertSee('Came in by manual entry')->assertSee('Cash')->assertDontSee('Reservation fee');
    }

    private function referral(string $type = 'fixed', int $value = 500, int $points = 100, bool $enabled = true): Guest
    {
        ReferralSetting::instance()->update(['enabled' => $enabled, 'discount_type' => $type, 'discount_value' => $value, 'owner_points' => $points]);
        $owner = Guest::factory()->create(['name' => 'Juan dela Cruz']);
        Referrals::codeFor($owner);

        return $owner->refresh();
    }

    public function test_a_referral_code_takes_its_discount_off_first_and_awards_the_owner_once_when_saved_exited(): void
    {
        $owner = $this->referral();
        $this->actingAs($this->staff);

        Livewire::test(ManualTransactionPage::class)
            ->fillForm($this->form(['services' => [$this->detail->id, $this->coating->id], 'discount' => 200, 'referral_code' => strtolower($owner->referral_code)]))
            ->call('save')
            ->assertHasNoFormErrors();

        $booking = Booking::sole();
        // 2,500 + 18,000, less the code's 500 and the typed 200
        $this->assertSame([19800, 700, $owner->id], [$booking->total_price, $booking->discount_amount, $booking->referral_guest_id]);

        $redemption = ReferralRedemption::sole();
        $this->assertSame([$owner->id, $booking->guest_id, $booking->id, 500, 100, 'awarded'], [$redemption->code_guest_id, $redemption->used_by_guest_id, $redemption->booking_id, $redemption->discount_amount, $redemption->points_awarded, $redemption->status()]);
        $this->assertSame(100, $owner->pointsBalance());

        // Saving the booking again does not award twice
        $booking->update(['admin_note' => 'again']);
        $booking->update(['status' => 'ready']);
        $booking->update(['status' => 'exited']);
        $this->assertSame(100, $owner->pointsBalance());
        $this->assertSame(1, ReferralRedemption::count());
    }

    public function test_a_percent_code_and_a_job_still_in_progress_awards_when_it_exits(): void
    {
        $owner = $this->referral('percent', 10, 40);
        $this->actingAs($this->staff);

        Livewire::test(ManualTransactionPage::class)
            ->fillForm($this->form(['status' => 'in_progress', 'services' => [$this->detail->id], 'referral_code' => $owner->referral_code]))
            ->call('save')
            ->assertHasNoFormErrors();

        $booking = Booking::sole();
        $this->assertSame([2250, 250], [$booking->total_price, $booking->discount_amount]);
        $this->assertSame('pending', ReferralRedemption::sole()->status());
        $this->assertSame(0, $owner->pointsBalance());

        $booking->update(['status' => 'exited']);
        $this->assertSame(40, $owner->pointsBalance());
    }

    public function test_the_typed_discount_cannot_take_more_than_the_price_with_a_code(): void
    {
        $owner = $this->referral('fixed', 2000, 10);
        $this->actingAs($this->staff);

        Livewire::test(ManualTransactionPage::class)
            ->fillForm($this->form(['services' => [$this->detail->id], 'discount' => 900, 'referral_code' => $owner->referral_code]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([0, 2500], [Booking::sole()->total_price, Booking::sole()->discount_amount]);
    }

    public function test_a_customer_with_an_earlier_booking_cannot_use_a_code(): void
    {
        $owner = $this->referral();
        $guest = Guest::factory()->create(['phone' => '0917 111 2222', 'branch_id' => $this->branch->id]);
        Booking::factory()->create(['branch_id' => $this->branch->id, 'guest_id' => $guest->id, 'status' => 'exited']);
        // A cancelled one does not count
        Booking::factory()->create(['branch_id' => $this->branch->id, 'guest_id' => $guest->id, 'status' => 'cancelled']);
        $this->actingAs($this->staff);
        $form = $this->form(['new_customer' => false, 'guest_id' => $guest->id, 'services' => [$this->detail->id], 'referral_code' => $owner->referral_code]);

        $page = Livewire::test(ManualTransactionPage::class)->fillForm($form)->call('save')->assertHasFormErrors(['referral_code']);

        $this->assertSame('Referral codes are for your first booking only.', $page->errors()->first('data.referral_code'));
        $this->assertSame([2, 0], [Booking::count(), ReferralRedemption::count()]);

        // With only the cancelled booking left, the code works
        Booking::where('status', 'exited')->delete();
        Livewire::test(ManualTransactionPage::class)->fillForm($form)->call('save')->assertHasNoFormErrors();
        $this->assertSame(1, ReferralRedemption::count());
    }

    public function test_a_customer_cannot_use_their_own_code_or_one_that_does_not_exist(): void
    {
        $owner = $this->referral();
        $owner->update(['phone' => '0917 333 4444', 'branch_id' => $this->branch->id]);
        $this->actingAs($this->staff);

        $page = Livewire::test(ManualTransactionPage::class)
            ->fillForm($this->form(['new_customer' => false, 'guest_id' => $owner->id, 'services' => [$this->detail->id], 'referral_code' => $owner->referral_code]))
            ->call('save')
            ->assertHasFormErrors(['referral_code']);
        $this->assertSame('You can’t use your own referral code.', $page->errors()->first('data.referral_code'));

        Livewire::test(ManualTransactionPage::class)
            ->fillForm($this->form(['services' => [$this->detail->id], 'referral_code' => 'NOPE-0000']))
            ->call('save')
            ->assertHasFormErrors(['referral_code']);

        // Nothing was saved, not even the new customer
        $this->assertSame([0, 1], [Booking::count(), Guest::count()]);
    }

    public function test_the_referral_field_is_hidden_while_referral_is_off_or_has_no_discount(): void
    {
        $this->actingAs($this->staff);

        $this->referral(enabled: false);
        Livewire::test(ManualTransactionPage::class)->assertFormFieldHidden('referral_code');

        ReferralSetting::instance()->update(['enabled' => true, 'discount_value' => null]);
        Livewire::test(ManualTransactionPage::class)->assertFormFieldHidden('referral_code');

        ReferralSetting::instance()->update(['discount_value' => 500]);
        Livewire::test(ManualTransactionPage::class)->assertFormFieldVisible('referral_code');
    }

    public function test_no_code_means_no_redemption(): void
    {
        $this->referral();
        $this->actingAs($this->staff);

        Livewire::test(ManualTransactionPage::class)->fillForm($this->form(['services' => [$this->detail->id]]))->call('save')->assertHasNoFormErrors();

        $this->assertSame(0, ReferralRedemption::count());
        $this->assertNull(Booking::sole()->referral_guest_id);
    }
}
