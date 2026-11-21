<?php

namespace Tests\Feature;

use App\Filament\Pages\ManualTransactionPage;
use App\Mail\CustomerBookingUpdate;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\MailSetting;
use App\Models\User;
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
}
