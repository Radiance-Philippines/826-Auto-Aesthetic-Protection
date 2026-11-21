<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Support\Schedule\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The daily cap: how many units drop off on a day, what counts toward it,
 * and the days a branch can't take at all.
 */
class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));

        $this->branch = Branch::factory()->cap(2)->create();
    }

    private function on(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    private function book(string $date, array $state = []): Booking
    {
        return Booking::factory()->on($date)->create(['branch_id' => $this->branch->id, ...$state]);
    }

    private function check(string $date, ?int $exceptId = null, bool $byStaff = false): string
    {
        return Availability::check($this->branch, $this->on($date), $exceptId, $byStaff);
    }

    public function test_a_day_takes_units_up_to_its_cap(): void
    {
        $this->book('2026-11-17');
        $this->assertSame('ok', $this->check('2026-11-17'));

        $this->book('2026-11-17');
        $this->assertSame('full', $this->check('2026-11-17'));
        $this->assertSame('ok', $this->check('2026-11-18'));
    }

    public function test_every_working_status_counts_toward_the_cap(): void
    {
        $this->branch->update(['daily_cap' => 10]);

        foreach (['payment_review', 'confirmed', 'arrived', 'in_progress', 'quality_check', 'ready', 'exited'] as $status) {
            $this->book('2026-11-17', ['status' => $status]);
        }

        $this->assertSame(7, Availability::unitsOn($this->branch, $this->on('2026-11-17')));
    }

    public function test_cancelled_declined_expired_and_no_show_free_the_day(): void
    {
        $this->branch->update(['daily_cap' => 1]);

        foreach (['cancelled', 'declined', 'expired', 'no_show'] as $status) {
            $this->book('2026-11-17', ['status' => $status]);
        }

        $this->assertSame('ok', $this->check('2026-11-17'));
    }

    public function test_an_unpaid_hold_counts_until_it_lapses_without_cron(): void
    {
        $this->branch->update(['daily_cap' => 1]);
        $this->book('2026-11-17', ['status' => 'awaiting_payment', 'hold_expires_at' => now()->addMinutes(30)]);

        $this->assertSame('full', $this->check('2026-11-17'));

        $this->travel(31)->minutes();

        $this->assertSame('ok', $this->check('2026-11-17'));
        $this->assertSame('expired', Booking::sole()->stage());
    }

    public function test_a_screenshot_to_check_counts_past_the_deadline(): void
    {
        $this->branch->update(['daily_cap' => 1]);
        $this->book('2026-11-17', ['status' => 'payment_review', 'hold_expires_at' => now()->subMinutes(5)]);

        $this->assertSame('full', $this->check('2026-11-17'));
    }

    public function test_home_service_and_other_branches_never_count(): void
    {
        $this->branch->update(['daily_cap' => 1]);
        Booking::factory()->home()->create(['branch_id' => $this->branch->id, 'status' => 'confirmed', 'drop_off_on' => '2026-11-17', 'ready_on' => '2026-11-17']);
        Booking::factory()->on('2026-11-17')->create();

        $this->assertSame('ok', $this->check('2026-11-17'));
    }

    public function test_a_fully_booked_block_stops_new_bookings_but_a_closure_closes_the_day(): void
    {
        BranchBlock::factory()->full()->between('2026-11-17 00:00', '2026-11-18 00:00')->create(['branch_id' => $this->branch->id]);
        BranchBlock::factory()->between('2026-11-18 00:00', '2026-11-19 00:00')->create(['branch_id' => null]);

        $this->assertSame('full', $this->check('2026-11-17'));
        $this->assertSame('closed', $this->check('2026-11-18'));
        $this->assertSame('ok', $this->check('2026-11-19'));
    }

    public function test_a_sunday_and_a_zero_cap_are_closed(): void
    {
        $this->assertSame('closed', $this->check('2026-11-22'));

        $this->branch->update(['daily_cap' => 0]);

        $this->assertSame('closed', $this->check('2026-11-17'));
    }

    public function test_the_notice_and_the_booking_window_are_enforced_for_customers_only(): void
    {
        // 1 day of notice from Monday: Tuesday is the first day
        $this->assertSame('too_soon', $this->check('2026-11-16'));
        $this->assertSame('ok', $this->check('2026-11-17'));
        $this->assertSame('ok', $this->check('2026-11-16', byStaff: true));

        $this->branch->update(['lead_days' => 3]);
        $this->assertSame('too_soon', $this->check('2026-11-18'));
        $this->assertSame('ok', $this->check('2026-11-19'));

        // 3 months ahead: up to the end of February
        $this->assertSame('ok', $this->check('2027-02-26'));
        $this->assertSame('too_far', $this->check('2027-03-01'));
        $this->assertSame('ok', $this->check('2027-03-01', byStaff: true));
    }

    public function test_moving_a_booking_doesnt_count_the_booking_itself(): void
    {
        $this->branch->update(['daily_cap' => 1]);
        $booking = $this->book('2026-11-17');

        $this->assertSame('full', $this->check('2026-11-17'));
        $this->assertSame('ok', $this->check('2026-11-17', $booking->id));
    }

    public function test_a_month_marks_days_open_full_or_closed(): void
    {
        $this->branch->update(['daily_cap' => 1]);
        $this->book('2026-11-17');
        BranchBlock::factory()->full()->between('2026-11-19 00:00', '2026-11-20 00:00')->create(['branch_id' => $this->branch->id]);

        $month = Availability::month($this->branch, $this->on('2026-11-01'));

        $this->assertSame('2026-11', $month['month']);
        $this->assertSame('2026-11-17', $month['first']);
        $this->assertSame('2027-02-28', $month['last']);
        $this->assertSame('closed', $month['days']['2026-11-15']);
        $this->assertSame('closed', $month['days']['2026-11-16']);
        $this->assertSame('full', $month['days']['2026-11-17']);
        $this->assertSame('open', $month['days']['2026-11-18']);
        $this->assertSame('full', $month['days']['2026-11-19']);
        $this->assertSame('closed', $month['days']['2026-11-22']);
        $this->assertCount(30, $month['days']);
    }
}
