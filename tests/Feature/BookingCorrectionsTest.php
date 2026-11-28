<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Mail\CustomerBookingUpdate;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\MailSetting;
use App\Models\ReferralRedemption;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Support\ManualTransaction;
use App\Support\Reservations;
use App\Support\Rewards;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\BuildsShop;
use Tests\TestCase;

/**
 * Changes made after a booking exists must not undo what was already agreed:
 * redeemed points, typed prices, a finished job's price, and the emails for
 * home visits.
 */
class BookingCorrectionsTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop();
        // Customer emails only go out once the shop's mailbox is saved
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
    }

    public function test_redeemed_points_stay_off_the_price_when_the_size_is_set_again(): void
    {
        ReferralSetting::instance()->update(['peso_per_point' => 10]);
        $guest = Guest::factory()->create();
        $friend = Guest::factory()->create();
        ReferralRedemption::create(['code_guest_id' => $guest->id, 'used_by_guest_id' => $friend->id, 'booking_id' => Booking::factory()->create(['branch_id' => $this->branch->id, 'guest_id' => $friend->id])->id])
            ->forceFill(['points_awarded' => 100, 'awarded_at' => now()])->save();
        $booking = Booking::factory()->create(['branch_id' => $this->branch->id, 'guest_id' => $guest->id]);
        $booking->items()->create(['service_id' => $this->detail->id, 'service_name' => 'Exterior detailing']);
        Reservations::reviewSize($booking, $this->medium);
        Rewards::redeem($guest, $this->owner, 50, $booking->fresh());

        Reservations::reviewSize($booking->fresh(), $this->medium);

        $this->assertSame([2000, 500], [(int) $booking->fresh()->total_price, (int) $booking->fresh()->discount_amount]);
        $this->assertSame(50, $guest->fresh()->pointsBalance());
    }

    public function test_a_manual_transaction_keeps_its_typed_prices_when_the_size_changes(): void
    {
        $booking = ManualTransaction::record($this->owner, [
            'branch_id' => $this->branch->id, 'name' => 'Pedro Reyes', 'phone' => '0922 333 4444',
            'vehicle_year' => 2021, 'vehicle_model' => 'Toyota Vios', 'vehicle_type' => 'sedan', 'plate_number' => 'ABC1234',
            'vehicle_size_id' => $this->medium->id, 'services' => [$this->detail->id], 'prices' => [$this->detail->id => 1800],
            'discount' => 100, 'service_date' => '2026-11-16', 'status' => 'arrived',
        ]);

        $this->assertSame([], Reservations::reviewSize($booking->fresh(), $this->medium));

        $booking->refresh();
        $this->assertSame([1700, 100, 1800], [(int) $booking->total_price, (int) $booking->discount_amount, (int) $booking->items()->sole()->price]);
        $this->assertNotNull($booking->priced_at);
    }

    public function test_a_finished_job_keeps_its_price_when_a_unit_detail_is_corrected(): void
    {
        $done = Booking::factory()->priced(5000)->status('exited')->create(['branch_id' => $this->branch->id, 'plate_number' => 'AAA1111']);
        $open = Booking::factory()->priced(5000)->status('confirmed')->create(['branch_id' => $this->branch->id, 'plate_number' => 'BBB2222']);

        foreach ([$done, $open] as $booking) {
            Livewire::test(EditBooking::class, ['record' => $booking->id])
                ->fillForm(['plate_number' => 'CCC3333'])
                ->call('save')
                ->assertHasNoFormErrors();
        }

        $this->assertNotNull($done->fresh()->priced_at);
        // An open one still goes back for a size review
        $this->assertNull($open->fresh()->priced_at);
    }

    public function test_a_home_visit_added_with_its_date_is_scheduled(): void
    {
        $form = ['kind' => 'home', 'source' => 'phone', 'branch_id' => $this->branch->id, 'services' => [$this->detail->id], 'vehicle_year' => 2021, 'vehicle_model' => 'Toyota Vios', 'vehicle_type' => 'sedan', 'plate_number' => 'ABC1234', 'address' => '1 Sample Street', 'name' => 'Maria Santos', 'phone' => '0917 123 4567', 'email' => 'maria@example.com'];

        Livewire::test(CreateBooking::class)->fillForm([...$form, 'drop_off_on' => '2026-11-19'])->call('create')->assertHasNoFormErrors();
        $scheduled = Booking::latest('id')->first();
        $this->assertSame(['confirmed', '2026-11-19'], [$scheduled->status, $scheduled->drop_off_on?->toDateString()]);
        $this->assertNotNull($scheduled->ready_on);
        Mail::assertSent(CustomerBookingUpdate::class, fn (CustomerBookingUpdate $mail) => $mail->kind === 'home_scheduled');

        Livewire::test(CreateBooking::class)->fillForm([...$form, 'plate_number' => 'XYZ9876'])->call('create')->assertHasNoFormErrors();
        $this->assertSame(['requested', null], [Booking::latest('id')->first()->status, Booking::latest('id')->first()->drop_off_on]);
    }

    public function test_a_new_visit_date_gets_its_own_reminder(): void
    {
        $booking = Booking::factory()->home()->status('confirmed')->create(['branch_id' => $this->branch->id, 'drop_off_on' => '2026-11-17', 'ready_on' => '2026-11-17', 'reminded_at' => now()]);

        Livewire::test(ViewBooking::class, ['record' => $booking->id])
            ->callAction('scheduleHome', ['drop_off_on' => '2026-11-20', 'ready_on' => '2026-11-20'])
            ->assertHasNoActionErrors();

        $this->assertNull($booking->fresh()->reminded_at);
    }

    public function test_home_visit_emails_do_not_talk_about_a_drop_off_or_a_blank_time(): void
    {
        $home = Booking::factory()->home()->status('awaiting_payment')->create(['branch_id' => $this->branch->id, 'drop_off_on' => '2026-11-17', 'ready_on' => '2026-11-17', 'hold_expires_at' => null]);

        $rejected = (new CustomerBookingUpdate($home, 'rejected'))->render();
        $this->assertStringContainsString('so we can confirm your visit', $rejected);
        $this->assertStringNotContainsString('to keep the day', $rejected);

        $reminder = new CustomerBookingUpdate($home, 'reminder');
        $this->assertStringNotContainsString('drop-off', $reminder->render());
        $this->assertStringContainsString('home service', $reminder->render());

        $onsite = Booking::factory()->status('awaiting_payment')->create(['branch_id' => $this->branch->id, 'hold_expires_at' => now()->addHour()]);
        $this->assertStringContainsString('to keep the day', (new CustomerBookingUpdate($onsite, 'rejected'))->render());
        $this->assertStringContainsString('drop-off', (new CustomerBookingUpdate($onsite, 'reminder'))->render());
    }
}
