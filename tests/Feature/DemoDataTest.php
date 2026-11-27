<?php

namespace Tests\Feature;

use App\Models\Backjob;
use App\Models\BackjobType;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\Guest;
use App\Models\Photo;
use App\Models\ReferralSetting;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Access;
use App\Support\Warranty;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('public');
        $this->seed();
    }

    public function test_it_adds_labelled_gallery_samples_and_removes_only_those(): void
    {
        config(['app.demo' => true]);
        $real = Photo::factory()->create(['caption' => 'Real work']);
        $this->artisan('826:demo-data')->assertSuccessful();
        $bookings = Booking::pluck('id')->all();

        // --media replaces the samples without touching the bookings
        $this->artisan('826:demo-data', ['--media' => true])->assertSuccessful();

        $this->assertSame($bookings, Booking::pluck('id')->all());
        $this->assertSame(count(Photo::CATEGORIES), Photo::where('caption', 'like', 'Sample:%')->count());
        $this->assertGreaterThan(0, Review::approved()->count());
        $this->assertSame(0, Review::where('body', 'not like', 'Sample:%')->count());
        $this->assertSame(0, Review::whereRelation('guest', 'google_id', Guest::DEMO_GOOGLE_ID)->count());
        Storage::disk('public')->assertExists(Photo::where('caption', 'like', 'Sample:%')->first()->image);

        $this->artisan('826:demo-data', ['--clear' => true])->assertSuccessful();

        $this->assertSame([$real->id], Photo::pluck('id')->all());
        $this->assertSame(0, Review::count());
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
        // Booked, then arrived in working hours (never a bare date at midnight), then picked up
        foreach ($demoCustomer->bookings->whereNotNull('arrived_at') as $visit) {
            $this->assertTrue($visit->created_at->lessThan($visit->arrived_at), 'booked before arriving');
            $this->assertNotSame('00:00', $visit->arrived_at->format('H:i'));
            $this->assertGreaterThanOrEqual(8, $visit->arrived_at->hour);
            $this->assertLessThan(18, $visit->arrived_at->hour);
            $this->assertTrue($visit->arrived_at->lessThanOrEqualTo(now()));
            $this->assertTrue($visit->exited_at === null || $visit->arrived_at->lessThan($visit->exited_at));
        }
        $this->assertCount(2, $demoCustomer->bookings->whereNotNull('arrived_at'));
        $sent = $exited->toCustomer();
        $this->assertSame('09:40', CarbonImmutable::parse($sent['arrivedAt'])->format('H:i'));
        $this->assertLessThan(strtotime($sent['arrivedAt']), strtotime($sent['createdAt']));
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

    public function test_it_makes_a_sample_team_per_branch_without_mail_or_invites(): void
    {
        config(['app.demo' => true]);
        Mail::fake();
        $real = User::factory()->owner()->create(['email' => 'sample-manager-cainta-real@example.com']);

        $this->artisan('826:demo-data')->assertSuccessful();

        // Cainta, San Mateo (the bookable ones) and Quezon City: manager, two detailers, sales admin each
        $this->assertSame(12, User::sample()->count());
        $cainta = User::where('email', 'sample-manager-cainta@example.com')->sole();
        $this->assertSame('Sample Branch manager (Cainta)', $cainta->name);
        $this->assertSame(Access::BRANCH_MANAGER, $cainta->role);
        $this->assertSame([Branch::where('slug', 'cainta')->value('id')], $cainta->branchIds());
        $this->assertSame(Access::OPERATIONS, User::where('email', 'sample-detailer-2-san-mateo@example.com')->value('role'));
        $this->assertSame(Access::SALES_ADMIN, User::where('email', 'sample-sales-quezon-city@example.com')->value('role'));

        User::sample()->get()->each(function (User $user) {
            $this->assertStringStartsWith('Sample ', $user->name);
            $this->assertStringEndsWith('@example.com', $user->email);
            $this->assertNotSame(Access::SUPER_ADMIN, $user->role);
            $this->assertCount(1, $user->branchIds());
            $this->assertFalse($user->isPendingInvite());
            $this->assertNull($user->invited_at);
            $this->assertFalse($user->hasTwoFactor());
            $this->assertTrue($user->isSample());
            $this->assertTrue($user->canAccessAnyPanel());
        });

        Mail::assertNothingOutgoing();
        $this->assertFalse($real->fresh()->isSample());

        // Nobody can sign in as them: a few common passwords fail
        foreach (['password', 'Password1', '12345678', 'sample', 'secret', 'sample-manager-cainta', 'password123'] as $guess) {
            $this->assertFalse(Hash::check($guess, $cainta->password), $guess);
        }

        // Running it again changes nothing, and keeps the same (unknown) passwords
        $before = User::sample()->pluck('password', 'email');
        $this->artisan('826:demo-data')->assertSuccessful();
        $this->assertSame(12, User::sample()->count());
        $this->assertEquals($before, User::sample()->pluck('password', 'email'));

        // --clear removes them, never a real or super admin account
        $this->artisan('826:demo-data', ['--clear' => true])->assertSuccessful();
        $this->assertSame(0, User::sample()->count());
        $this->assertTrue($real->fresh()->exists);
    }

    public function test_it_makes_no_team_accounts_outside_local_and_demo(): void
    {
        $this->artisan('826:demo-data')->assertFailed();

        $this->assertSame(0, User::sample()->count());
    }

    public function test_it_never_touches_a_super_admin_that_has_a_sample_address(): void
    {
        config(['app.demo' => true]);
        $owner = User::factory()->owner()->create(['email' => 'sample-manager-cainta@example.com']);

        $this->artisan('826:demo-data')->assertSuccessful();
        $this->assertSame(Access::SUPER_ADMIN, $owner->fresh()->role);
        $this->assertSame([], $owner->fresh()->branchIds());

        $this->artisan('826:demo-data', ['--clear' => true])->assertSuccessful();
        $this->assertTrue($owner->fresh()->exists);
    }
}
