<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchBlock;
use App\Support\Schedule\Estimate;
use App\Support\Schedule\OpenCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Open days and the day estimate, before bookings come in. The branch is
 * open Monday to Saturday, closed on Sunday.
 */
class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));

        $this->branch = Branch::factory()->create();
    }

    private function on(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    private function calendar(): OpenCalendar
    {
        return OpenCalendar::for($this->branch->refresh(), $this->on('2026-11-16'), $this->on('2026-12-31'));
    }

    public function test_a_one_day_job_is_ready_the_day_it_is_dropped_off(): void
    {
        $this->assertSame('2026-11-17', $this->calendar()->readyOn($this->on('2026-11-17'), 1)->toDateString());
    }

    public function test_the_drop_off_day_counts_as_day_one(): void
    {
        // Tue, Wed, Thu
        $this->assertSame('2026-11-19', $this->calendar()->readyOn($this->on('2026-11-17'), 3)->toDateString());
    }

    public function test_the_closed_weekday_is_skipped(): void
    {
        // Fri, Sat, (Sun closed), Mon
        $this->assertSame('2026-11-23', $this->calendar()->readyOn($this->on('2026-11-20'), 3)->toDateString());
    }

    public function test_a_closure_in_the_middle_of_a_job_moves_the_ready_date_later(): void
    {
        BranchBlock::factory()->between('2026-11-30 00:00', '2026-12-01 00:00')->create(['title' => 'Bonifacio Day']);

        // Sat Nov 28, (Sun), (Mon holiday), Tue Dec 1
        $this->assertSame('2026-12-01', $this->calendar()->readyOn($this->on('2026-11-28'), 2)->toDateString());
    }

    public function test_a_closure_for_every_branch_counts_too_and_a_full_day_does_not(): void
    {
        BranchBlock::factory()->between('2026-11-17 00:00', '2026-11-18 00:00')->create(['branch_id' => null]);
        BranchBlock::factory()->full()->between('2026-11-18 00:00', '2026-11-19 00:00')->create(['branch_id' => $this->branch->id]);

        $calendar = $this->calendar();

        $this->assertFalse($calendar->isOpen($this->on('2026-11-17')));
        $this->assertTrue($calendar->isOpen($this->on('2026-11-18')));
        // Nov 17 is closed: Wed (full, but the shop works), Thu
        $this->assertSame('2026-11-19', $calendar->readyOn($this->on('2026-11-17'), 2)->toDateString());
    }

    public function test_a_multi_day_closure_covers_each_of_its_days(): void
    {
        BranchBlock::factory()->between('2026-12-24 00:00', '2026-12-27 00:00')->create(['branch_id' => $this->branch->id]);

        $calendar = $this->calendar();

        foreach (['2026-12-24', '2026-12-25', '2026-12-26'] as $day) {
            $this->assertFalse($calendar->isOpen($this->on($day)), $day);
        }

        $this->assertTrue($calendar->isOpen($this->on('2026-12-23')));
        $this->assertTrue($calendar->isOpen($this->on('2026-12-28')));
    }

    public function test_a_job_more_than_120_days_out_has_no_ready_date(): void
    {
        $this->assertNull($this->calendar()->readyOn($this->on('2026-11-17'), 200));
        $this->assertNull($this->calendar()->readyOn($this->on('2026-11-17'), 0));
    }

    public function test_bad_or_inverted_hours_count_as_closed(): void
    {
        $this->branch->update(['weekly_hours' => ['1' => ['open' => '18:00', 'close' => '08:00'], '2' => ['open' => 'noon', 'close' => '18:00'], '3' => ['open' => '8:00', 'close' => '17:00']]]);

        $hours = $this->branch->refresh()->weeklyHours();

        $this->assertNull($hours[1]);
        $this->assertNull($hours[2]);
        $this->assertSame(['open' => '08:00', 'close' => '17:00'], $hours[3]);
        $this->assertNull($hours[7]);
    }

    public function test_estimate_labels(): void
    {
        $this->assertSame('1 day', (new Estimate(1))->label());
        $this->assertSame('about 3 days', (new Estimate(3))->label());
        $this->assertSame('', (new Estimate(0))->label());
        $this->assertSame(['days' => 3, 'label' => 'about 3 days'], (new Estimate(3))->toArray());
    }
}
