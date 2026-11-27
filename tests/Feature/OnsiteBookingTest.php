<?php

namespace Tests\Feature;

use App\Mail\CustomerBookingUpdate;
use App\Mail\TeamBookingAlert;
use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\BranchBlock;
use App\Models\Guest;
use App\Models\GuestToken;
use App\Models\MailSetting;
use App\Models\PaymentSetting;
use App\Models\Service;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsShop;
use Tests\TestCase;

/**
 * Customers reserving a drop-off date on the site, with their unit and no
 * price until the admin has seen it.
 */
class OnsiteBookingTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop();
        $this->guest = Guest::factory()->create(['email' => 'maria@example.com']);
    }

    private function reserve(array $overrides = [])
    {
        return $this->actingAs($this->guest, 'guest')->postJson('/api/bookings', $this->reservation($overrides));
    }

    public function test_with_the_fee_off_a_reservation_is_confirmed_at_once_with_no_price(): void
    {
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);

        $this->reserve()
            ->assertCreated()
            ->assertJsonPath('booking.stage', 'confirmed')
            ->assertJsonPath('booking.dropOffOn', '2026-11-17')
            ->assertJsonPath('booking.readyOn', '2026-11-17')
            ->assertJsonPath('booking.estimate', ['days' => 1, 'label' => '1 day'])
            ->assertJsonPath('booking.when', 'Tue, Nov 17 · ready around Tue, Nov 17')
            ->assertJsonPath('booking.price', ['status' => 'reviewing', 'total' => null, 'subtotal' => null, 'discount' => null, 'items' => [['name' => 'Exterior detailing', 'price' => null]]])
            ->assertJsonPath('booking.items.0', ['name' => 'Exterior detailing', 'category' => 'Detailing', 'estimateDays' => 1])
            ->assertJsonPath('booking.vehicle.year', 2021)
            ->assertJsonPath('booking.vehicle.typeLabel', 'Sedan')
            ->assertJsonPath('booking.vehicle.plate', 'ABC1234')
            ->assertJsonPath('booking.vehicle.size', null)
            ->assertJsonPath('booking.vehicle.sizeReviewed', false)
            ->assertJsonPath('booking.arrivedAt', null);

        $booking = Booking::sole();
        $this->assertSame(['onsite', 'confirmed', 'site'], [$booking->kind, $booking->status, $booking->source]);
        $this->assertSame(['2026-11-17', '2026-11-17', 1], [$booking->drop_off_on->toDateString(), $booking->ready_on->toDateString(), $booking->total_days]);
        $this->assertNull($booking->total_price);
        $this->assertNull($booking->priced_at);
        $this->assertMatchesRegularExpression('/^826-[2-9A-HJ-NP-Z]{5}$/', $booking->reference);
        $this->assertSame($this->guest->id, $booking->guest_id);
        $this->assertNull($booking->amount_due);

        // The customer's unit is kept for next time, still unsized
        $vehicle = Vehicle::sole();
        $this->assertSame([$this->guest->id, 2021, 'Toyota Vios', 'sedan', 'ABC1234', false], [$vehicle->guest_id, $vehicle->year, $vehicle->model, $vehicle->type, $vehicle->plate_number, $vehicle->isReviewed()]);
        $this->assertSame($vehicle->id, $booking->vehicle_id);

        // The team is asked to size it; the customer's mail has no price
        Mail::assertSent(TeamBookingAlert::class, fn (TeamBookingAlert $mail) => $mail->kind === 'size_review');
        Mail::assertSent(CustomerBookingUpdate::class, fn (CustomerBookingUpdate $mail) => $mail->kind === 'reserved' && $mail->hasTo('maria@example.com') && ! str_contains($mail->render(), '₱'));
    }

    public function test_with_the_fee_on_the_day_is_held_for_a_fixed_fee(): void
    {
        PaymentSetting::instance()->update(['reservation_fee_enabled' => true, 'amount_value' => 500, 'hold_minutes' => 45, 'gcash_enabled' => true, 'gcash_number' => '0917 000 0000']);

        $this->reserve()
            ->assertCreated()
            ->assertJsonPath('booking.stage', 'awaiting_payment')
            ->assertJsonPath('booking.amountDue', 500)
            ->assertJsonPath('booking.holdExpiresAt', '2026-11-16T07:45:00+08:00')
            ->assertJsonPath('booking.methods.0.key', 'gcash');

        $booking = Booking::sole();
        $this->assertSame(['awaiting_payment', 500, 'fee'], [$booking->status, $booking->amount_due, $booking->amount_kind]);
    }

    public function test_the_estimate_adds_up_the_services_days_and_skips_the_closed_day(): void
    {
        // Fri Nov 20, Sat, (Sun closed), Mon, Tue: 1 + 3 = 4 open days
        $this->reserve(['services' => [$this->coating->id, $this->detail->id], 'date' => '2026-11-20'])
            ->assertCreated()
            ->assertJsonPath('booking.estimate', ['days' => 4, 'label' => 'about 4 days'])
            ->assertJsonPath('booking.readyOn', '2026-11-24')
            ->assertJsonPath('booking.when', 'Fri, Nov 20 · ready around Tue, Nov 24');

        $this->assertSame(['Exterior detailing', 'Graphene coating'], Booking::sole()->items->pluck('service_name')->all());
    }

    public function test_a_service_without_an_estimate_or_not_offered_at_the_branch_is_refused(): void
    {
        Service::query()->whereKey($this->coating->id)->update(['estimate_days' => null]);
        $this->reserve(['services' => [$this->coating->id]])->assertUnprocessable()->assertJsonValidationErrors('services');

        Service::query()->whereKey($this->coating->id)->update(['estimate_days' => 3]);
        $this->branch->services()->detach($this->detail->id);
        $this->reserve()->assertUnprocessable()->assertJsonValidationErrors('services');

        $this->assertSame(0, Booking::count());
    }

    public function test_dates_that_are_closed_too_soon_too_far_or_full_are_refused_with_a_reason(): void
    {
        $this->reserve(['date' => '2026-11-16'])->assertUnprocessable()->assertJsonValidationErrors(['date' => 'too soon']);
        $this->reserve(['date' => '2026-11-22'])->assertUnprocessable()->assertJsonValidationErrors(['date' => 'closed']);
        $this->reserve(['date' => '2027-03-01'])->assertUnprocessable()->assertJsonValidationErrors(['date' => 'too far']);
        $this->reserve(['date' => 'not a date'])->assertUnprocessable()->assertJsonValidationErrors('date');

        BranchBlock::factory()->full()->between('2026-11-18 00:00', '2026-11-19 00:00')->create(['branch_id' => $this->branch->id]);
        $this->reserve(['date' => '2026-11-18'])->assertUnprocessable()->assertJsonValidationErrors(['date' => 'fully booked']);

        $this->assertSame(0, Booking::count());
    }

    public function test_the_last_place_of_a_day_goes_to_one_customer(): void
    {
        $this->branch->update(['daily_cap' => 1]);

        $this->reserve()->assertCreated();

        $other = Guest::factory()->create();
        $this->actingAs($other, 'guest')->postJson('/api/bookings', $this->reservation(['plate_number' => 'XYZ 999']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'fully booked']);

        $this->assertSame(1, Booking::count());
    }

    public function test_a_busy_branch_lock_asks_to_try_again(): void
    {
        // Waiting for a lock needs the clock to run
        $this->travelBack();

        $lock = Cache::lock("booking:branch:{$this->branch->id}", 30);
        $lock->get();

        $date = CarbonImmutable::today()->addDays(9);
        $date = $date->isSunday() ? $date->addDay() : $date;

        try {
            $this->reserve(['date' => $date->toDateString()])->assertStatus(409);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Vehicle::count());
    }

    public function test_one_unpaid_hold_and_a_few_upcoming_bookings_per_customer(): void
    {
        PaymentSetting::instance()->update(['reservation_fee_enabled' => true, 'amount_value' => 500, 'gcash_enabled' => true, 'gcash_number' => '0917 000 0000']);

        $this->reserve()->assertCreated();
        $this->reserve(['date' => '2026-11-18', 'plate_number' => 'DEF 456'])->assertUnprocessable()->assertJsonValidationErrors(['date' => 'waiting for its reservation fee']);

        // Once the hold lapses, it no longer counts
        $this->travel(31)->minutes();
        PaymentSetting::instance()->update(['reservation_fee_enabled' => false]);
        BookingSetting::instance()->update(['max_upcoming_per_guest' => 2]);
        $this->reserve(['date' => '2026-11-19', 'plate_number' => 'DEF 456'])->assertCreated();

        $this->reserve(['date' => '2026-11-18'])->assertCreated();
        $this->reserve(['date' => '2026-11-20', 'plate_number' => 'GHI 789'])->assertUnprocessable()->assertJsonValidationErrors(['date' => 'upcoming bookings']);
    }

    public function test_a_draft_branch_or_one_with_something_missing_takes_no_bookings(): void
    {
        $this->branch->update(['daily_cap' => 0]);
        $this->reserve()->assertUnprocessable()->assertJsonValidationErrors('branch');

        $this->branch->update(['daily_cap' => 2, 'status' => 'draft']);
        $this->reserve()->assertUnprocessable()->assertJsonValidationErrors('branch');

        $this->branch->update(['status' => 'open']);
        PaymentSetting::instance()->update(['reservation_fee_enabled' => true]);
        $this->reserve()->assertUnprocessable()->assertJsonValidationErrors('branch');
    }

    public function test_the_unit_and_the_details_are_checked(): void
    {
        $this->reserve(['phone' => '123', 'plate_number' => '', 'vehicle_model' => '', 'vehicle_year' => '', 'vehicle_type' => '', 'consent' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'plate_number', 'vehicle_model', 'vehicle_year', 'vehicle_type', 'consent']);

        $this->reserve(['vehicle_type' => 'spaceship', 'vehicle_year' => 1900])->assertUnprocessable()->assertJsonValidationErrors(['vehicle_type', 'vehicle_year']);

        $this->assertSame(0, Booking::count());
    }

    public function test_the_honeypot_is_quietly_accepted(): void
    {
        $this->reserve(['fax_number' => 'spam'])->assertCreated();

        $this->assertSame(0, Booking::count());
    }

    public function test_a_saved_unit_is_reused_by_id_or_by_plate_and_only_the_guests_own(): void
    {
        $this->reserve()->assertCreated();
        $vehicle = Vehicle::sole();

        // By id: no unit fields needed
        $this->reserve(['vehicle_id' => $vehicle->id, 'date' => '2026-11-18', 'vehicle_year' => null, 'vehicle_model' => null, 'vehicle_type' => null, 'plate_number' => null])
            ->assertCreated()
            ->assertJsonPath('booking.vehicle.model', 'Toyota Vios');

        // By the same plate: no second unit
        $this->reserve(['date' => '2026-11-19', 'plate_number' => 'abc-1234'])->assertCreated();
        $this->assertSame(1, Vehicle::count());

        // Someone else's unit is refused
        $other = Guest::factory()->create();
        $this->actingAs($other, 'guest')->postJson('/api/bookings', $this->reservation(['vehicle_id' => $vehicle->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plate_number');
    }

    public function test_the_signed_in_customer_lists_only_their_saved_units(): void
    {
        $mine = Vehicle::factory()->reviewed()->create(['guest_id' => $this->guest->id, 'model' => 'Honda City', 'year' => 2019, 'plate_number' => 'AAA111']);
        Vehicle::factory()->create(['guest_id' => Guest::factory()->create()->id]);

        $this->getJson('/api/my-vehicles')->assertUnauthorized();

        $this->actingAs($this->guest, 'guest')->getJson('/api/my-vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'vehicles')
            ->assertJsonPath('vehicles.0', ['id' => $mine->id, 'year' => 2019, 'model' => 'Honda City', 'type' => 'sedan', 'plate' => 'AAA111', 'sizeReviewed' => true]);
    }

    public function test_a_month_of_open_days_with_the_estimate_and_nothing_private(): void
    {
        $this->branch->update(['daily_cap' => 1]);
        Booking::factory()->on('2026-11-17')->create(['branch_id' => $this->branch->id, 'name' => 'Secret Person']);

        $query = http_build_query(['branch' => $this->branch->id, 'services' => [$this->coating->id], 'month' => '2026-11']);

        $this->getJson("/api/availability?{$query}")
            ->assertOk()
            ->assertJsonPath('month', '2026-11')
            ->assertJsonPath('first', '2026-11-17')
            ->assertJsonPath('days.2026-11-16', 'closed')
            ->assertJsonPath('days.2026-11-17', 'full')
            ->assertJsonPath('days.2026-11-18', 'open')
            ->assertJsonPath('days.2026-11-22', 'closed')
            ->assertJsonPath('estimate', ['days' => 3, 'label' => 'about 3 days'])
            ->assertDontSee('Secret Person');

        // The day-by-day endpoint is gone
        $this->getJson('/api/availability/day?'.$query.'&date=2026-11-18')->assertNotFound();
    }

    public function test_a_demo_checks_everything_and_saves_nothing(): void
    {
        config(['app.demo' => true]);

        $this->postJson('/api/bookings', $this->reservation())->assertCreated()->assertJson(['demo' => true, 'readyOn' => '2026-11-17']);
        $this->postJson('/api/bookings', $this->reservation(['date' => '2026-11-22']))->assertUnprocessable();
        $this->getJson('/api/my-vehicles')->assertOk()->assertJsonCount(0, 'vehicles');

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Vehicle::count());
    }

    public function test_a_demo_keeps_a_booking_from_the_signed_in_app_and_emails_nobody(): void
    {
        config(['app.demo' => true]);
        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);
        $phone = $this->guest->phone;

        $this->withToken(GuestToken::issue($this->guest, 'Test phone'))
            ->postJson('/api/bookings', $this->reservation(['email' => 'someone@example.com']))
            ->assertCreated()
            ->assertJsonPath('booking.stage', 'confirmed');

        $booking = Booking::sole();
        $this->assertSame(['demo', 'confirmed', null], [$booking->source, $booking->status, $booking->email]);
        $this->assertSame($phone, $this->guest->refresh()->phone);
        Mail::assertNothingSent();

        $this->artisan('826:demo-data', ['--clear' => true])->assertSuccessful();
        $this->assertSame(0, Booking::count());
    }

    public function test_later_service_changes_leave_booked_items_alone(): void
    {
        $this->reserve()->assertCreated();

        $this->detail->update(['name' => 'Renamed', 'estimate_days' => 5]);

        $booking = Booking::sole();
        $this->assertSame(['Exterior detailing', 1, 1], [$booking->items[0]->service_name, $booking->items[0]->duration_days, $booking->total_days]);
        $this->assertSame('2026-11-17', $booking->ready_on->toDateString());
    }
}
