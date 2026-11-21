<?php

namespace Tests\Feature;

use App\Mail\CustomerBookingUpdate;
use App\Mail\TeamBookingAlert;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\MailSetting;
use App\Models\ServiceRate;
use App\Models\Vehicle;
use App\Models\VehicleSize;
use App\Support\Reservations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsShop;
use Tests\TestCase;

/**
 * No price until the admin has seen the unit; a unit that was reviewed
 * before is priced on its next booking, unless its class has no price.
 */
class SizeReviewTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret']);

        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop();
        $this->guest = Guest::factory()->create(['email' => 'maria@example.com']);
    }

    private function reserve(array $overrides = [])
    {
        return $this->actingAs($this->guest, 'guest')->postJson('/api/bookings', $this->reservation($overrides));
    }

    public function test_a_new_unit_is_not_priced_and_the_price_fields_stay_empty(): void
    {
        $this->reserve(['services' => [$this->detail->id, $this->coating->id]])
            ->assertCreated()
            ->assertJsonPath('booking.price.status', 'reviewing')
            ->assertJsonPath('booking.price.total', null)
            ->assertJsonPath('booking.price.items.1.price', null);

        $booking = Booking::sole();
        $this->assertNull($booking->total_price);
        $this->assertSame([null, null], $booking->items->pluck('price')->all());
        $this->assertTrue($booking->needsSizeReview());
        Mail::assertSent(TeamBookingAlert::class, fn (TeamBookingAlert $mail) => $mail->kind === 'size_review');
        Mail::assertNotSent(TeamBookingAlert::class, fn (TeamBookingAlert $mail) => $mail->kind === 'reserved');
    }

    public function test_a_saved_reviewed_unit_is_priced_instantly_from_its_size_class(): void
    {
        $vehicle = Vehicle::factory()->reviewed($this->medium)->create(['guest_id' => $this->guest->id, 'plate_number' => 'ABC1234']);

        $this->reserve(['vehicle_id' => $vehicle->id, 'services' => [$this->detail->id, $this->coating->id], 'vehicle_year' => null, 'vehicle_model' => null, 'vehicle_type' => null, 'plate_number' => null])
            ->assertCreated()
            ->assertJsonPath('booking.price.status', 'set')
            ->assertJsonPath('booking.price.total', 20500)
            ->assertJsonPath('booking.price.items', [['name' => 'Exterior detailing', 'price' => 2500], ['name' => 'Graphene coating', 'price' => 18000]])
            ->assertJsonPath('booking.vehicle.size', 'Medium')
            ->assertJsonPath('booking.vehicle.sizeReviewed', true);

        $booking = Booking::sole();
        $this->assertSame([20500, 'Medium'], [$booking->total_price, $booking->vehicle_size_name]);
        $this->assertNotNull($booking->priced_at);
        $this->assertFalse($booking->needsSizeReview());
        Mail::assertSent(TeamBookingAlert::class, fn (TeamBookingAlert $mail) => $mail->kind === 'reserved');
        Mail::assertNotSent(TeamBookingAlert::class, fn (TeamBookingAlert $mail) => $mail->kind === 'size_review');
    }

    public function test_typing_the_same_plate_again_finds_the_reviewed_unit_but_a_changed_model_needs_a_new_review(): void
    {
        $vehicle = Vehicle::factory()->reviewed($this->medium)->create(['guest_id' => $this->guest->id, 'year' => 2021, 'model' => 'Toyota Vios', 'type' => 'sedan', 'plate_number' => 'ABC1234']);

        $this->reserve()->assertCreated()->assertJsonPath('booking.price.total', 2500);
        $this->assertSame(1, Vehicle::count());

        $this->reserve(['date' => '2026-11-18', 'vehicle_model' => 'Mitsubishi Montero', 'vehicle_type' => 'suv'])
            ->assertCreated()
            ->assertJsonPath('booking.price.status', 'reviewing');

        $this->assertFalse($vehicle->refresh()->isReviewed());
        $this->assertSame('Mitsubishi Montero', $vehicle->model);
    }

    public function test_a_saved_unit_whose_class_has_no_price_for_a_service_still_needs_review(): void
    {
        ServiceRate::query()->where('service_id', $this->coating->id)->delete();
        $vehicle = Vehicle::factory()->reviewed($this->medium)->create(['guest_id' => $this->guest->id, 'plate_number' => 'ABC1234']);

        $this->reserve(['vehicle_id' => $vehicle->id, 'services' => [$this->detail->id, $this->coating->id], 'vehicle_year' => null, 'vehicle_model' => null, 'vehicle_type' => null, 'plate_number' => null])
            ->assertCreated()
            ->assertJsonPath('booking.price.status', 'reviewing')
            ->assertJsonPath('booking.price.total', null)
            ->assertJsonPath('booking.price.items.0.price', null);

        $booking = Booking::sole();
        $this->assertNull($booking->total_price);
        $this->assertNull($booking->priced_at);
        // The class is known, but the booking is still on the team's review list
        $this->assertSame('Medium', $booking->vehicle_size_name);
        $this->assertTrue($booking->needsSizeReview());
        $this->assertTrue(Booking::query()->needsSizeReview()->whereKey($booking->id)->exists());
        Mail::assertSent(TeamBookingAlert::class, fn (TeamBookingAlert $mail) => $mail->kind === 'size_review');
    }

    public function test_applying_a_size_prices_the_items_and_reports_the_missing_rates(): void
    {
        $large = VehicleSize::factory()->create(['name' => 'Large']);
        $booking = Booking::factory()->create(['branch_id' => $this->branch->id, 'total_days' => 4]);
        $booking->items()->createMany([
            ['service_id' => $this->detail->id, 'service_name' => 'Exterior detailing', 'duration_days' => 1],
            ['service_id' => $this->coating->id, 'service_name' => 'Graphene coating', 'duration_days' => 3],
        ]);

        $this->assertSame(['Exterior detailing', 'Graphene coating'], Reservations::applySize($booking, $large));
        $this->assertNull($booking->refresh()->total_price);

        $this->assertSame([], Reservations::applySize($booking, $this->medium));
        $this->assertSame(20500, $booking->refresh()->total_price);
        $this->assertSame([2500, 18000], $booking->items->pluck('price')->all());
    }

    public function test_the_customer_sees_the_price_in_their_booking_only_after_the_review(): void
    {
        $this->reserve()->assertCreated();
        $booking = Booking::sole();

        $this->getJson("/api/my-bookings/{$booking->id}?key={$booking->access_key}")->assertOk()->assertJsonPath('booking.price.status', 'reviewing');

        Reservations::applySize($booking, $this->medium);

        $this->getJson("/api/my-bookings/{$booking->id}?key={$booking->access_key}")
            ->assertOk()
            ->assertJsonPath('booking.price', ['status' => 'set', 'total' => 2500, 'items' => [['name' => 'Exterior detailing', 'price' => 2500]]])
            ->assertJsonPath('booking.vehicle.size', 'Medium');

        $mail = new CustomerBookingUpdate($booking->refresh(), 'priced');
        $this->assertStringContainsString('2,500', $mail->render());
    }
}
