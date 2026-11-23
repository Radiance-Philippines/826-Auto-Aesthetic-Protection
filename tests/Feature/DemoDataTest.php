<?php

namespace Tests\Feature;

use App\Models\Backjob;
use App\Models\BackjobType;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\Guest;
use App\Models\ReferralSetting;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\Vehicle;
use App\Support\Warranty;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sample estimates, prices, caps and bookings: only for local testing and the demo.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->seed();
    }

    public function test_it_refuses_outside_local_and_demo(): void
    {
        $this->artisan('826:demo-data')->assertFailed();

        $this->assertSame(0, ServiceRate::count());
        $this->assertSame(0, Booking::count());
    }

    public function test_it_fills_blanks_adds_marked_bookings_and_clears_only_those(): void
    {
        config(['app.demo' => true]);
        $real = Booking::factory()->create(['branch_id' => Branch::first()->id]);
        Branch::where('slug', 'san-mateo')->update(['daily_cap' => 5]);

        $this->artisan('826:demo-data')->assertSuccessful();

        $this->assertGreaterThan(0, ServiceRate::count());
        $this->assertSame(0, Service::whereNull('estimate_days')->count());
        $this->assertSame(5, Branch::where('slug', 'san-mateo')->value('daily_cap'));
        $this->assertGreaterThan(0, Branch::where('slug', 'cainta')->value('daily_cap'));
        $this->assertTrue(Branch::where('slug', 'cainta')->sole()->isOnsiteBookable());

        // One booking in every working status, one waiting for its size review
        $demo = Booking::where('source', 'demo')->get();
        $this->assertSame(14, $demo->count());
        $this->assertEqualsCanonicalizing(
            ['confirmed', 'arrived', 'in_progress', 'quality_check', 'ready', 'exited', 'payment_review', 'awaiting_payment', 'requested'],
            $demo->pluck('status')->unique()->values()->all(),
        );
        $this->assertSame(['arrived'], $demo->where('status', 'arrived')->map(fn (Booking $booking) => $booking->arrived_at ? 'arrived' : 'missing')->unique()->values()->all());
        $this->assertTrue($demo->where('status', 'exited')->every(fn (Booking $booking) => $booking->exited_at !== null));
        $this->assertGreaterThan(0, $demo->filter->needsSizeReview()->count());
        $this->assertGreaterThan(0, $demo->filter(fn (Booking $booking) => $booking->total_price > 0)->count());
        $this->assertGreaterThanOrEqual(3, Vehicle::count());
        $this->assertSame(1, BranchBlock::where('kind', 'full')->count());

        // Sample customers without Google, warranty months, backjob types and a referral discount, all marked sample
        $this->assertSame(1, Guest::whereNull('google_id')->where('notes', 'Sample data')->count());
        $this->assertGreaterThan(0, Service::whereNotNull('warranty_months')->count());
        $this->assertSame(3, BackjobType::where('name', 'like', 'Sample:%')->count());
        $this->assertSame(1, Backjob::count());
        $this->assertSame('₱500 off', ReferralSetting::current()->label());

        // The sign-in button's customer: 2 units, one in the shop, one booked, one finished and under warranty, a code and two friends
        $demoCustomer = Guest::where('google_id', Guest::DEMO_GOOGLE_ID)->sole();
        $this->assertSame(2, $demoCustomer->vehicles()->count());
        $this->assertSame(['confirmed', 'exited', 'in_progress'], $demoCustomer->bookings->pluck('status')->sort()->values()->all());
        $this->assertSame('cainta', $demoCustomer->bookings->first()->branch->slug);
        $this->assertNotNull($demoCustomer->referral_code);
        $this->assertSame(['awarded', 'pending'], $demoCustomer->referralRedemptions->map->status()->sort()->values()->all());
        $this->assertSame(100, $demoCustomer->pointsBalance());
        $exited = $demoCustomer->bookings->firstWhere('status', 'exited');
        $this->assertSame('active', Warranty::status($exited, $exited->items->first()));

        // Running it again leaves one demo customer, not two
        $this->artisan('826:demo-data')->assertSuccessful();
        $this->assertSame(1, Guest::where('google_id', Guest::DEMO_GOOGLE_ID)->count());
        $this->assertSame(14, Booking::where('source', 'demo')->count());

        $this->artisan('826:demo-data', ['--clear' => true])->assertSuccessful();

        $this->assertSame([$real->id], Booking::pluck('id')->all());
        $this->assertSame(0, Guest::where('google_id', 'like', 'demo-%')->count());
        $this->assertSame(0, Guest::count());
        $this->assertSame(0, BackjobType::count());
        $this->assertSame(0, Backjob::count());
        $this->assertSame(0, Vehicle::count());
        $this->assertSame(0, BranchBlock::count());
    }
}
