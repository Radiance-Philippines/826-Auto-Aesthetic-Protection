<?php

namespace Tests\Feature;

use App\Http\Middleware\AppPin;
use App\Models\Booking;
use App\Models\Guest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsShop;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The customer app's calls end to end: sign in, reserve, and the unit is in
 * the branch's queue for the team, with the demo's team PIN on.
 */
class AppBookingToQueueTest extends TestCase
{
    use BuildsShop, RefreshDatabase, StaffApi;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->buildShop();
    }

    public function test_a_booking_from_the_app_is_in_the_staff_queue(): void
    {
        config(['app.demo' => true, 'app.site_pin' => '314159']);
        $pin = [AppPin::HEADER => '314159'];

        $token = $this->postJson('/api/app/sign-in/demo', [], $pin)->assertOk()->json('token');

        $this->postJson('/api/bookings', $this->reservation(['date' => '2026-11-17', 'plate_number' => 'APP 1234']), ['Authorization' => "Bearer {$token}", ...$pin])
            ->assertCreated()
            ->assertJsonPath('booking.dropOffOn', '2026-11-17');

        $this->assertSame(Guest::demoCustomer()->id, Booking::sole()->guest_id);

        // The customer guard of the app's request must not stand in for the team member
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $operations = $this->staffUser('operations', Booking::sole()->branch);

        $booked = $this->getJson('/api/staff/queue?branch='.Booking::sole()->branch_id.'&date=2026-11-17', $this->bearer($operations))
            ->assertOk()
            ->json('columns.0');

        $this->assertSame('confirmed', $booked['key']);
        $this->assertSame([Booking::sole()->reference], array_column($booked['cards'], 'reference'));
    }
}
