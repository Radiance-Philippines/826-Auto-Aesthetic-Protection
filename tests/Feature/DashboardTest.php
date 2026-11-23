<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\BranchesInsight;
use App\Filament\Widgets\CustomersInsight;
use App\Filament\Widgets\NeedsAttention;
use App\Filament\Widgets\OperationsInsight;
use App\Filament\Widgets\SalesChart;
use App\Filament\Widgets\SalesOverview;
use App\Filament\Widgets\ServicesInsight;
use App\Filament\Widgets\StaffInsight;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\Conversation;
use App\Models\Guest;
use App\Models\ReferralRedemption;
use App\Models\Review;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Support\Dashboard\Insights;
use App\Support\Dashboard\Period;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The owner dashboard: every number against a hand-worked set of bookings,
 * who sees what, the filters and the CSV. "Today" is Monday, Nov 16, 2026,
 * so the default period (last 30 days) is Oct 18 to Nov 16.
 *
 * The set (Cainta unless noted):
 *  A  exited Nov 10, ₱5,000 after ₱500 off, paid ₱5,000 on Nov 10, customer g1, arrived Nov 3 (ready Nov 5)
 *  B  exited Oct 1 (before the period), ₱9,000, customer g1: makes g1 a repeat customer
 *  J  exited Nov 13, ₱1,000, customer g2, arrived Nov 12, no ready time
 *  C  San Mateo, exited Nov 16, ₱3,000, customer g2, arrived Nov 14 (ready Nov 15 9 PM)
 *  F  cancelled and G no-show, both due in the period; D a fee paid ₱500 on Nov 12 (still booked)
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $cainta;

    private Branch $sanMateo;

    private Guest $g1;

    private Guest $g2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
        $this->g1 = Guest::factory()->create(['name' => 'Maria Santos']);
        $this->g2 = Guest::factory()->create(['name' => 'Jun Reyes']);
    }

    private function staff(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->state(['role' => $role])->create();
        $user->branches()->attach($branch ?? $this->cainta);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function job(Branch $branch, string $exitedAt, int $price, array $state = []): Booking
    {
        return Booking::factory()->create([
            'branch_id' => $branch->id,
            'status' => 'exited',
            'exited_at' => $exitedAt,
            'drop_off_on' => substr($exitedAt, 0, 10),
            'ready_on' => substr($exitedAt, 0, 10),
            'total_price' => $price,
            'priced_at' => $exitedAt,
            ...$state,
        ]);
    }

    private function fixture(): void
    {
        $a = $this->job($this->cainta, '2026-11-10 12:00:00', 5000, ['guest_id' => $this->g1->id, 'drop_off_on' => '2026-11-01', 'discount_amount' => 500, 'amount_paid' => 5000, 'paid_at' => '2026-11-10 12:00:00', 'arrived_at' => '2026-11-03 09:00:00', 'ready_at' => '2026-11-05 09:00:00']);
        $this->job($this->cainta, '2026-10-01 12:00:00', 9000, ['guest_id' => $this->g1->id, 'drop_off_on' => '2026-11-14']);
        $this->job($this->cainta, '2026-11-13 09:00:00', 1000, ['guest_id' => $this->g2->id, 'arrived_at' => '2026-11-12 09:00:00', 'ready_at' => null]);
        $this->job($this->sanMateo, '2026-11-16 09:00:00', 3000, ['guest_id' => $this->g2->id, 'arrived_at' => '2026-11-14 09:00:00', 'ready_at' => '2026-11-15 21:00:00']);

        Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => 'cancelled', 'drop_off_on' => '2026-11-05', 'total_price' => 7000]);
        Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => 'no_show', 'drop_off_on' => '2026-11-06']);
        Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => 'confirmed', 'amount_due' => 500, 'paid_at' => '2026-11-12 10:00:00']);
        // Paid before the period: not collected
        Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => 'confirmed', 'amount_due' => 800, 'paid_at' => '2026-10-01 10:00:00']);

        ReferralRedemption::create(['code_guest_id' => $this->g2->id, 'used_by_guest_id' => $this->g1->id, 'booking_id' => $a->id, 'discount_amount' => 500, 'points_awarded' => 50, 'awarded_at' => '2026-11-10 12:00:00']);
    }

    private function insights(User $user, ?array $filters = null): Insights
    {
        return Insights::for($user, $filters);
    }

    // Period

    public function test_each_period_runs_from_its_first_day_to_its_last(): void
    {
        $range = fn (?array $filters) => ($period = Period::from($filters))->key.' '.$period->from->toDateString().' '.$period->to->toDateString();

        $this->assertSame('last_30 2026-10-18 2026-11-16', $range(null));
        $this->assertSame('today 2026-11-16 2026-11-16', $range(['period' => 'today']));
        $this->assertSame('last_7 2026-11-10 2026-11-16', $range(['period' => 'last_7']));
        $this->assertSame('this_month 2026-11-01 2026-11-16', $range(['period' => 'this_month']));
        $this->assertSame('last_month 2026-10-01 2026-10-31', $range(['period' => 'last_month']));
        $this->assertSame('this_year 2026-01-01 2026-11-16', $range(['period' => 'this_year']));
        $this->assertSame('custom 2026-11-02 2026-11-09', $range(['period' => 'custom', 'from' => '2026-11-09', 'to' => '2026-11-02']));
        // A custom period without both dates, or a made-up one, falls back
        $this->assertSame('last_30 2026-10-18 2026-11-16', $range(['period' => 'custom', 'from' => '2026-11-09']));
        $this->assertSame('last_30 2026-10-18 2026-11-16', $range(['period' => 'whenever']));
    }

    // Sales

    public function test_sales_count_jobs_by_the_day_they_exited_and_leave_out_cancelled_ones(): void
    {
        $this->fixture();
        $sales = $this->insights(User::factory()->owner()->create())->sales();

        // A + J + C: B exited before the period, and the cancelled booking never finished
        $this->assertSame(3, $sales['units']);
        $this->assertSame(9000, $sales['sales']);
        $this->assertSame(3000, $sales['average']);
        $this->assertSame(500, $sales['discounts']);
    }

    public function test_collected_is_the_amount_paid_or_else_the_fee_by_paid_date(): void
    {
        $this->fixture();

        // A's ₱5,000 paid + D's ₱500 fee; the ₱800 fee was paid before the period
        $this->assertSame(5500, $this->insights(User::factory()->owner()->create())->sales()['collected']);
        $this->assertSame(0, $this->insights(User::factory()->owner()->create(), ['period' => 'today'])->sales()['collected']);
    }

    public function test_the_lost_rate_is_lost_over_everything_due_in_the_period_that_ended(): void
    {
        $this->fixture();
        $sales = $this->insights(User::factory()->owner()->create())->sales();

        // Due in the period: A, B, J, C finished, F cancelled, G no-show (D is a booking a week out)
        $this->assertSame(6, $sales['ended']);
        $this->assertSame(2, $sales['lost']);
        $this->assertSame(33.3, $sales['lostRate']);
    }

    public function test_new_and_repeat_customers_look_at_earlier_finished_jobs(): void
    {
        $this->fixture();
        $sales = $this->insights(User::factory()->owner()->create())->sales();

        // g1 finished B before the period, so is repeat; g2 has nothing earlier
        $this->assertSame(1, $sales['newCustomers']);
        $this->assertSame(1, $sales['repeatCustomers']);
    }

    public function test_referral_redemptions_and_points_come_from_the_finished_jobs(): void
    {
        $this->fixture();
        $sales = $this->insights(User::factory()->owner()->create())->sales();

        $this->assertSame(1, $sales['redemptions']);
        $this->assertSame(50, $sales['points']);

        $this->assertSame(0, $this->insights(User::factory()->owner()->create(), ['period' => 'today'])->sales()['redemptions']);
    }

    public function test_the_chart_series_has_a_point_for_every_day_and_a_week_for_a_long_period(): void
    {
        $this->fixture();
        $owner = User::factory()->owner()->create();

        $daily = $this->insights($owner, ['period' => 'custom', 'from' => '2026-11-09', 'to' => '2026-11-11'])->series();
        $this->assertSame(['Nov 9', 'Nov 10', 'Nov 11'], $daily['labels']);
        $this->assertSame([0, 5000, 0], $daily['sales']);

        // Oct 1 to Nov 16 is long enough for weeks: B (Oct 1, a Thursday) falls in the week of Sep 28
        $weekly = $this->insights($owner, ['period' => 'custom', 'from' => '2026-09-28', 'to' => '2026-11-29'])->series();
        $this->assertTrue($weekly['weekly']);
        $this->assertSame('Week of Sep 28', $weekly['labels'][0]);
        $this->assertSame(9000, $weekly['sales'][0]);
        $this->assertSame(5000, $weekly['sales'][array_search('Week of Nov 9', $weekly['labels'], true)] - 1000);
    }

    public function test_the_sales_widget_shows_the_numbers_with_what_they_mean(): void
    {
        $this->fixture();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(SalesOverview::class, ['pageFilters' => null])
            ->assertSee('₱9,000')
            ->assertSee('₱5,500')
            ->assertSee('₱3,000')
            ->assertSee('33.3%')
            ->assertSee('1 new, 1 repeat')
            ->assertSee('₱500')
            ->assertSee('Total price of jobs that exited in this period, after discounts.')
            ->assertDontSee('No finished jobs in this period.');
    }

    public function test_an_empty_period_says_so_instead_of_showing_zeros(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(SalesOverview::class, ['pageFilters' => null])
            ->assertSee('No finished jobs in this period.')
            ->assertDontSee('₱0');

        Livewire::test(SalesChart::class, ['pageFilters' => null])->assertSee('No finished jobs in this period.');
        Livewire::test(ServicesInsight::class, ['pageFilters' => null])->assertSee('No finished jobs in this period.');
    }

    // Services

    public function test_services_are_counted_from_the_finished_jobs_items(): void
    {
        $a = $this->job($this->cainta, '2026-11-10 12:00:00', 5000, ['guest_id' => $this->g1->id]);
        $a->items()->createMany([
            ['service_name' => 'Exterior detailing', 'category_name' => 'Detailing', 'price' => 3000],
            ['service_name' => 'Graphene coating', 'category_name' => 'Coating', 'price' => 2500],
        ]);
        $c = $this->job($this->sanMateo, '2026-11-16 09:00:00', 3000);
        $c->items()->create(['service_name' => 'Exterior detailing', 'category_name' => 'Detailing', 'price' => 3000]);
        // Not finished: its items don't count
        Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => 'cancelled'])->items()->create(['service_name' => 'Exterior detailing', 'category_name' => 'Detailing', 'price' => 3000]);

        $data = $this->insights(User::factory()->owner()->create())->services();
        $services = collect($data['services'])->keyBy('name');

        $this->assertSame(['name' => 'Exterior detailing', 'units' => 2, 'sales' => 6000], $services['Exterior detailing']);
        $this->assertSame(['name' => 'Graphene coating', 'units' => 1, 'sales' => 2500], $services['Graphene coating']);
        $this->assertSame([['name' => 'Detailing', 'units' => 2, 'sales' => 6000], ['name' => 'Coating', 'units' => 1, 'sales' => 2500]], $data['categories']);
    }

    // Branches and turnaround

    public function test_the_branch_table_compares_each_branch(): void
    {
        $this->fixture();
        $table = collect($this->insights(User::factory()->owner()->create())->branchTable())->keyBy('name');

        // Cainta: A and J finished; A, B, J, F and G were due (G is the one no-show)
        $this->assertSame(2, $table['Cainta']['units']);
        $this->assertSame(6000, $table['Cainta']['sales']);
        $this->assertSame(3000, $table['Cainta']['average']);
        $this->assertSame(20.0, $table['Cainta']['noShowRate']);
        $this->assertSame(1, $table['San Mateo']['units']);
        $this->assertSame(3000, $table['San Mateo']['sales']);
        $this->assertSame(0.0, $table['San Mateo']['noShowRate']);
    }

    public function test_turnaround_skips_jobs_missing_a_time_and_hand_entered_ones(): void
    {
        $this->fixture();
        // Typed in by hand: arrived, ready and exited are all the same noon, so they say nothing
        $this->job($this->cainta, '2026-11-13 12:00:00', 2000, ['source' => 'manual', 'arrived_at' => '2026-11-13 12:00:00', 'ready_at' => '2026-11-13 12:00:00']);

        $turnaround = $this->insights(User::factory()->owner()->create())->turnaround();

        // To ready: A 2 days and C 1.5 days (J has no ready time) = 1.75
        $this->assertSame(1.8, $turnaround['toReady']);
        // To exit: A 7.125, J 1, C 2 = 3.375
        $this->assertSame(3.4, $turnaround['toExit']);
        // Cainta alone: A is its only job with a ready time (2 days); A and J to exit = (7.125 + 1) / 2
        $this->assertSame(2.0, $turnaround['byBranch'][$this->cainta->id]['toReady']);
        $this->assertSame(4.1, $turnaround['byBranch'][$this->cainta->id]['toExit']);
    }

    public function test_turnaround_is_empty_when_no_job_has_the_times(): void
    {
        $this->job($this->cainta, '2026-11-13 09:00:00', 1000);

        $turnaround = $this->insights(User::factory()->owner()->create())->turnaround();

        $this->assertNull($turnaround['toReady']);
        $this->assertNull($turnaround['toExit']);
    }

    // Operations

    public function test_units_in_the_shop_are_counted_by_stage(): void
    {
        foreach (['confirmed', 'confirmed', 'arrived', 'in_progress', 'quality_check', 'ready', 'exited', 'cancelled'] as $status) {
            Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => $status]);
        }
        Booking::factory()->create(['branch_id' => $this->sanMateo->id, 'status' => 'arrived']);

        $owner = User::factory()->owner()->create();

        $this->assertSame(['Booked' => 2, 'Arrived' => 2, 'In progress' => 1, 'Quality check' => 1, 'Ready' => 1], $this->insights($owner)->stages());
        $this->assertSame(['Booked' => 2, 'Arrived' => 1, 'In progress' => 1, 'Quality check' => 1, 'Ready' => 1], $this->insights($owner, ['branch' => $this->cainta->id])->stages());
    }

    public function test_utilization_honors_closed_days_and_days_marked_full(): void
    {
        // Mon Nov 16 to Sun Nov 29: 14 days, 2 of them Sundays, plus a closure on Wed Nov 18 = 11 open days at a cap of 2
        BranchBlock::factory()->create(['branch_id' => $this->cainta->id, 'starts_at' => '2026-11-18', 'ends_at' => '2026-11-19']);
        BranchBlock::factory()->full()->create(['branch_id' => $this->cainta->id, 'starts_at' => '2026-11-20', 'ends_at' => '2026-11-21']);

        foreach (['2026-11-17', '2026-11-17', '2026-11-19', '2026-11-20'] as $day) {
            Booking::factory()->on($day)->create(['branch_id' => $this->cainta->id]);
        }
        // On the closed day and on a Sunday: nothing to measure against
        Booking::factory()->on('2026-11-18')->create(['branch_id' => $this->cainta->id]);
        Booking::factory()->on('2026-11-22')->create(['branch_id' => $this->cainta->id]);
        // Past the 14 days, and a cancelled one: not counted
        Booking::factory()->on('2026-11-30')->create(['branch_id' => $this->cainta->id]);
        Booking::factory()->on('2026-11-19')->status('cancelled')->create(['branch_id' => $this->cainta->id]);

        $data = $this->insights(User::factory()->owner()->create(), ['branch' => $this->cainta->id])->utilization();

        // Used: Nov 17 two, Nov 19 one, Nov 20 marked full (counts as the cap, 2) = 5 of 22
        $this->assertSame([['name' => 'Cainta', 'percent' => 23, 'openDays' => 11, 'fullDays' => 1, 'booked' => 4]], $data['branches']);
        $this->assertSame(
            [['2026-11-17', 2, false], ['2026-11-20', 1, true], ['2026-11-19', 1, false]],
            array_map(fn (array $day) => [$day['date'], $day['booked'], $day['marked']], $data['heaviest']),
        );
    }

    public function test_a_block_for_every_branch_closes_each_branch(): void
    {
        BranchBlock::factory()->create(['branch_id' => null, 'starts_at' => '2026-11-18', 'ends_at' => '2026-11-19']);

        $data = $this->insights(User::factory()->owner()->create())->utilization();

        $this->assertSame([11, 11], array_column($data['branches'], 'openDays'));
    }

    // Needs attention

    public function test_needs_attention_counts_what_is_waiting_at_the_users_branches(): void
    {
        Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => 'confirmed']);
        Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => 'payment_review']);
        Booking::factory()->home()->create(['branch_id' => $this->cainta->id]);
        // Priced, in the shop: one past its ready date, one ready today
        Booking::factory()->priced()->on('2026-11-12', '2026-11-14')->status('arrived')->create(['branch_id' => $this->cainta->id]);
        Booking::factory()->priced()->on('2026-11-14', '2026-11-16')->status('ready')->create(['branch_id' => $this->cainta->id]);
        // Another branch's
        Booking::factory()->create(['branch_id' => $this->sanMateo->id, 'status' => 'payment_review']);

        $booking = Booking::factory()->status('exited')->create(['branch_id' => $this->cainta->id, 'guest_id' => $this->g1->id]);
        Review::factory()->create(['guest_id' => $this->g1->id, 'booking_id' => $booking->id, 'branch_id' => $this->cainta->id]);
        Review::factory()->create(['branch_id' => $this->sanMateo->id]);
        Conversation::create(['branch_id' => $this->cainta->id, 'external_id' => 'a', 'unread_count' => 2]);
        Conversation::create(['branch_id' => $this->cainta->id, 'external_id' => 'b', 'unread_count' => 0]);
        Conversation::create(['branch_id' => $this->sanMateo->id, 'external_id' => 'c', 'unread_count' => 5]);

        $manager = $this->staff('branch_manager');

        $this->assertSame(
            ['size' => 3, 'screenshots' => 1, 'home' => 1, 'reviews' => 1, 'chats' => 1, 'late' => 1],
            $this->insights($manager)->attention(),
        );
        $this->assertSame(
            ['size' => 4, 'screenshots' => 2, 'home' => 1, 'reviews' => 2, 'chats' => 2, 'late' => 1],
            $this->insights(User::factory()->owner()->create())->attention(),
        );

        $this->actingAs($manager);
        Livewire::test(NeedsAttention::class, ['pageFilters' => null])
            ->assertSee('Units to size')
            ->assertSee('Screenshots to check')
            ->assertSee('Late units');
    }

    public function test_each_role_only_gets_the_attention_boxes_it_acts_on(): void
    {
        Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => 'payment_review']);

        $operations = $this->insights($this->staff('operations'))->attention();
        $sales = $this->insights($this->staff('sales_admin'))->attention();

        $this->assertSame(['size' => null, 'screenshots' => null, 'home' => null, 'reviews' => null, 'chats' => null, 'late' => 0], $operations);
        $this->assertNull($sales['late']);
        $this->assertSame(1, $sales['screenshots']);
    }

    // Customers

    public function test_customer_and_review_numbers(): void
    {
        // Two new customers in the period (one at Cainta, one without a branch but who booked there), one before it
        Guest::factory()->create(['branch_id' => $this->cainta->id, 'created_at' => '2026-11-12 10:00:00']);
        $booked = Guest::factory()->create(['created_at' => '2026-11-13 10:00:00']);
        Booking::factory()->create(['branch_id' => $this->cainta->id, 'guest_id' => $booked->id]);
        Guest::factory()->create(['branch_id' => $this->cainta->id, 'created_at' => '2026-08-01 10:00:00']);
        Guest::factory()->create(['branch_id' => $this->sanMateo->id, 'created_at' => '2026-11-12 10:00:00']);

        $this->g1->forceFill(['created_at' => '2026-01-01'])->save();
        $this->g2->forceFill(['created_at' => '2026-01-01'])->save();

        // Top customers: g1 ₱9,000 on two jobs, g2 ₱3,000
        $this->job($this->cainta, '2026-11-10 12:00:00', 5000, ['guest_id' => $this->g1->id]);
        $this->job($this->cainta, '2026-11-11 12:00:00', 4000, ['guest_id' => $this->g1->id]);
        $this->job($this->cainta, '2026-11-12 12:00:00', 3000, ['guest_id' => $this->g2->id]);

        // Reviews: 5 and 4 approved at Cainta, one pending, one approved elsewhere
        foreach ([5, 4] as $rating) {
            Review::factory()->approved()->create(['rating' => $rating, 'branch_id' => $this->cainta->id]);
        }
        Review::factory()->create(['rating' => 1, 'branch_id' => $this->cainta->id]);
        Review::factory()->approved()->create(['rating' => 1, 'branch_id' => $this->sanMateo->id]);

        $data = $this->insights($this->staff('branch_manager'))->customers();

        $this->assertSame(2, $data['new']);
        $this->assertSame(5, $data['total']);
        $this->assertSame(4.5, $data['rating']);
        $this->assertSame(2, $data['reviews']);
        $this->assertSame([['name' => 'Maria Santos', 'units' => 2, 'spend' => 9000], ['name' => 'Jun Reyes', 'units' => 1, 'spend' => 3000]], $data['top']);

        // Without sales, no spend list; without reviews, no rating
        $operations = $this->insights($this->staff('operations'))->customers();
        $this->assertNull($operations['top']);
        $this->assertNull($operations['rating']);
        $this->assertNull($operations['reviews']);
    }

    public function test_warranties_ending_in_the_next_30_days_are_counted_once_per_job(): void
    {
        // 5 months from Jun 20 ends Nov 20 (in 4 days); two services on one job count as one job
        $soon = $this->job($this->cainta, '2026-06-20 12:00:00', 1000);
        $soon->items()->createMany([['service_name' => 'Coating', 'price' => 500, 'warranty_months' => 5], ['service_name' => 'Tint', 'price' => 500, 'warranty_months' => 5]]);
        // Ends Dec 16 (30 days out): counted. Ends Dec 17: not.
        $this->job($this->cainta, '2026-09-16 12:00:00', 1000)->items()->create(['service_name' => 'Coating', 'price' => 500, 'warranty_months' => 3]);
        $this->job($this->cainta, '2026-09-17 12:00:00', 1000)->items()->create(['service_name' => 'Coating', 'price' => 500, 'warranty_months' => 3]);
        // Already ended, no warranty, and another branch
        $this->job($this->cainta, '2025-12-01 12:00:00', 1000)->items()->create(['service_name' => 'Coating', 'price' => 500, 'warranty_months' => 11]);
        $this->job($this->cainta, '2026-06-20 12:00:00', 1000)->items()->create(['service_name' => 'Wash', 'price' => 500]);
        $this->job($this->sanMateo, '2026-06-20 12:00:00', 1000)->items()->create(['service_name' => 'Coating', 'price' => 500, 'warranty_months' => 5]);

        $this->assertSame(2, $this->insights($this->staff('branch_manager'))->customers()['warranty']);
        $this->assertSame(3, $this->insights(User::factory()->owner()->create())->customers()['warranty']);

        // No warranty area, no warranty count
        $limited = User::factory()->state(['role' => 'branch_manager', 'permissions' => ['dashboard']])->create();
        $limited->branches()->attach($this->cainta);
        $this->assertNull($this->insights($limited)->customers()['warranty']);
    }

    // Staff

    public function test_staff_numbers_are_jobs_for_their_customers_and_status_moves_in_the_period(): void
    {
        $manager = $this->staff('branch_manager');
        $rep = $this->staff('sales_admin');
        $this->g1->update(['sales_rep_id' => $rep->id]);

        $this->job($this->cainta, '2026-11-10 12:00:00', 5000, ['guest_id' => $this->g1->id]);
        $this->job($this->cainta, '2026-11-11 12:00:00', 2000, ['guest_id' => $this->g1->id]);
        $this->job($this->cainta, '2026-11-12 12:00:00', 9000, ['guest_id' => $this->g2->id]);

        $booking = Booking::factory()->create(['branch_id' => $this->cainta->id]);
        $other = Booking::factory()->create(['branch_id' => $this->sanMateo->id]);

        foreach ([[$manager, $booking, 'status', '2026-11-10 09:00:00'], [$manager, $booking, 'status', '2026-11-11 09:00:00'], [$manager, $booking, 'ready_on', '2026-11-11 09:00:00'], [$manager, $booking, 'status', '2026-09-01 09:00:00'], [$manager, $other, 'status', '2026-11-11 09:00:00'], [null, $booking, 'status', '2026-11-11 09:00:00']] as [$user, $for, $kind, $at]) {
            BookingEvent::forceCreate(['booking_id' => $for->id, 'user_id' => $user?->id, 'kind' => $kind, 'from' => 'a', 'to' => 'b', 'created_at' => $at]);
        }

        $owner = collect($this->insights(User::factory()->owner()->create())->staff())->keyBy('name');
        $this->assertSame(['name' => $rep->name, 'role' => 'Sales admin', 'jobs' => 2, 'sales' => 7000, 'moves' => 0, 'referrals' => 0], $owner[$rep->name]);
        $this->assertSame(3, $owner[$manager->name]['moves']);

        // The manager only sees Cainta's: the San Mateo move is not theirs to count
        $scoped = collect($this->insights($manager)->staff())->keyBy('name');
        $this->assertSame(2, $scoped[$manager->name]['moves']);
    }

    public function test_points_redeemed_and_outstanding_come_from_the_rows_for_the_customers_you_can_see(): void
    {
        $this->fixture();
        // g2's code earned 50. Another customer only San Mateo sees earned 30 and spent 5
        $far = Guest::factory()->create(['name' => 'Ana Lim', 'branch_id' => $this->sanMateo->id]);
        $farJob = $this->job($this->sanMateo, '2026-11-15 12:00:00', 2000, ['guest_id' => $this->g1->id]);
        ReferralRedemption::create(['code_guest_id' => $far->id, 'used_by_guest_id' => $this->g1->id, 'booking_id' => $farJob->id, 'points_awarded' => 30, 'awarded_at' => '2026-11-15 12:00:00']);
        // Voided and pending ones earned nothing
        ReferralRedemption::create(['code_guest_id' => $this->g2->id, 'used_by_guest_id' => $this->g1->id, 'booking_id' => Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => 'cancelled'])->id, 'voided_at' => '2026-11-14 12:00:00']);

        foreach ([[$this->g2, 20, '2026-11-12 10:00:00'], [$this->g2, 10, '2026-10-01 10:00:00'], [$far, 5, '2026-11-14 10:00:00']] as [$guest, $points, $at]) {
            RewardRedemption::forceCreate(['guest_id' => $guest->id, 'points' => $points, 'note' => 'test', 'created_at' => $at]);
        }

        $owner = $this->insights(User::factory()->owner()->create())->sales();
        // In the period: 20 + 5; everything earned 80, everything spent 35
        $this->assertSame([25, 45, 80], [$owner['redeemed'], $owner['outstanding'], $owner['earnedEver']]);

        $this->assertSame([0, 45], [$this->insights(User::factory()->owner()->create(), ['period' => 'today'])->sales()['redeemed'], $this->insights(User::factory()->owner()->create(), ['period' => 'today'])->sales()['outstanding']]);

        // Cainta's manager sees g2 (booked there) but not Ana: 50 - 30
        $cainta = $this->insights($this->staff('branch_manager'))->sales();
        $this->assertSame([20, 20, 50], [$cainta['redeemed'], $cainta['outstanding'], $cainta['earnedEver']]);

        $sanMateo = $this->insights($this->staff('branch_manager', $this->sanMateo))->sales();
        $this->assertSame([25, 45, 80], [$sanMateo['redeemed'], $sanMateo['outstanding'], $sanMateo['earnedEver']]);
    }

    public function test_the_sales_boxes_show_points_redeemed_and_outstanding_only_to_sales_users(): void
    {
        $this->fixture();
        RewardRedemption::forceCreate(['guest_id' => $this->g2->id, 'points' => 20, 'note' => 'test', 'created_at' => '2026-11-12 10:00:00']);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(SalesOverview::class)
            ->assertSee('Points redeemed')
            ->assertSee('Points outstanding')
            ->assertSee('Points customers spent in this period')
            ->assertSee('minus all redeemed');

        $this->actingAs($this->staff('operations'));
        $this->assertFalse(SalesOverview::canView());
    }

    public function test_top_referrers_count_finished_jobs_in_the_period_and_their_points(): void
    {
        $this->fixture();
        // g2's code: A (50 points) + a second job in the period (30) + one before the period + one still booked
        $second = $this->job($this->cainta, '2026-11-12 12:00:00', 1500, ['guest_id' => $this->g1->id]);
        ReferralRedemption::create(['code_guest_id' => $this->g2->id, 'used_by_guest_id' => $this->g1->id, 'booking_id' => $second->id, 'points_awarded' => 30, 'awarded_at' => '2026-11-12 12:00:00']);
        $old = $this->job($this->cainta, '2026-10-01 12:00:00', 1500, ['guest_id' => $this->g1->id]);
        ReferralRedemption::create(['code_guest_id' => $this->g2->id, 'used_by_guest_id' => $this->g1->id, 'booking_id' => $old->id, 'points_awarded' => 99, 'awarded_at' => '2026-10-01 12:00:00']);
        $open = Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => 'confirmed']);
        ReferralRedemption::create(['code_guest_id' => $this->g1->id, 'used_by_guest_id' => $this->g2->id, 'booking_id' => $open->id]);
        // A code owner only San Mateo sees
        $far = Guest::factory()->create(['name' => 'Ana Lim', 'branch_id' => $this->sanMateo->id]);
        $farJob = $this->job($this->sanMateo, '2026-11-15 12:00:00', 2000);
        ReferralRedemption::create(['code_guest_id' => $far->id, 'used_by_guest_id' => $this->g1->id, 'booking_id' => $farJob->id, 'points_awarded' => 10, 'awarded_at' => '2026-11-15 12:00:00']);

        $owner = $this->insights(User::factory()->owner()->create())->customers()['referrers'];
        $this->assertSame([['name' => 'Jun Reyes', 'used' => 2, 'points' => 80], ['name' => 'Ana Lim', 'used' => 1, 'points' => 10]], $owner);

        // Cainta's manager: the San Mateo job and its owner are not theirs
        $this->assertSame([['name' => 'Jun Reyes', 'used' => 2, 'points' => 80]], $this->insights($this->staff('branch_manager'))->customers()['referrers']);

        // Needs sales and customers: operations has neither, sales admin has both
        $this->assertNull($this->insights($this->staff('operations'))->customers()['referrers']);
        $this->assertNotNull($this->insights($this->staff('sales_admin'))->customers()['referrers']);

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(CustomersInsight::class)->assertSee('Top referrers')->assertSee('Jun Reyes')->assertSee('Times used');
    }

    public function test_each_sales_rep_s_referrals_are_the_finished_jobs_where_their_customer_used_a_code(): void
    {
        $this->fixture();
        $rep = $this->staff('sales_admin');
        $other = $this->staff('sales_admin');
        // g1 used a code on A (fixture), and is the rep's customer; g2 is the other rep's
        $this->g1->update(['sales_rep_id' => $rep->id]);
        $this->g2->update(['sales_rep_id' => $other->id]);
        $second = $this->job($this->cainta, '2026-11-12 12:00:00', 1500, ['guest_id' => $this->g1->id]);
        ReferralRedemption::create(['code_guest_id' => $this->g2->id, 'used_by_guest_id' => $this->g1->id, 'booking_id' => $second->id, 'points_awarded' => 30, 'awarded_at' => '2026-11-12 12:00:00']);
        // Not finished, before the period, voided and at another branch: none count
        $open = Booking::factory()->create(['branch_id' => $this->cainta->id, 'status' => 'confirmed']);
        ReferralRedemption::create(['code_guest_id' => $this->g2->id, 'used_by_guest_id' => $this->g1->id, 'booking_id' => $open->id]);
        $old = $this->job($this->cainta, '2026-10-01 12:00:00', 1500);
        ReferralRedemption::create(['code_guest_id' => $this->g2->id, 'used_by_guest_id' => $this->g1->id, 'booking_id' => $old->id, 'points_awarded' => 5, 'awarded_at' => '2026-10-01 12:00:00']);
        $far = $this->job($this->sanMateo, '2026-11-15 12:00:00', 2000);
        ReferralRedemption::create(['code_guest_id' => $this->g2->id, 'used_by_guest_id' => $this->g1->id, 'booking_id' => $far->id, 'points_awarded' => 5, 'awarded_at' => '2026-11-15 12:00:00']);

        $all = collect($this->insights(User::factory()->owner()->create())->staff())->keyBy('name');
        $this->assertSame([3, 0], [$all[$rep->name]['referrals'], $all[$other->name]['referrals']]);

        $scoped = collect($this->insights($this->staff('branch_manager'))->staff())->keyBy('name');
        $this->assertSame(2, $scoped[$rep->name]['referrals']);

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(StaffInsight::class)->assertSee('Referrals');
    }

    // Who sees what

    public function test_each_role_sees_its_own_widgets(): void
    {
        $sees = function (User $user): array {
            $this->actingAs($user);

            return collect([SalesOverview::class, SalesChart::class, ServicesInsight::class, BranchesInsight::class, OperationsInsight::class, CustomersInsight::class, StaffInsight::class, NeedsAttention::class])
                ->filter(fn (string $widget) => $widget::canView())
                ->map(fn (string $widget) => class_basename($widget))
                ->values()
                ->all();
        };

        $this->assertSame(['SalesOverview', 'SalesChart', 'ServicesInsight', 'BranchesInsight', 'OperationsInsight', 'CustomersInsight', 'StaffInsight', 'NeedsAttention'], $sees(User::factory()->owner()->create()));

        // One branch: nothing to compare
        $this->assertSame(['SalesOverview', 'SalesChart', 'ServicesInsight', 'OperationsInsight', 'CustomersInsight', 'StaffInsight', 'NeedsAttention'], $sees($this->staff('branch_manager')));

        $both = $this->staff('branch_manager');
        $both->branches()->attach($this->sanMateo);
        $this->assertContains('BranchesInsight', $sees($both));

        // No revenue, and no staff list
        $this->assertSame(['OperationsInsight', 'CustomersInsight', 'NeedsAttention'], $sees($this->staff('operations')));

        // No queue or operations, and no staff list
        $this->assertSame(['SalesOverview', 'SalesChart', 'ServicesInsight', 'CustomersInsight', 'NeedsAttention'], $sees($this->staff('sales_admin')));
    }

    public function test_branch_staff_never_see_another_branchs_numbers(): void
    {
        $this->fixture();
        $manager = $this->staff('branch_manager');

        // Cainta only: A and J, not San Mateo's C
        $this->assertSame(6000, $this->insights($manager)->sales()['sales']);
        // Asking for San Mateo changes nothing
        $this->assertSame(6000, $this->insights($manager, ['branch' => $this->sanMateo->id])->sales()['sales']);
        $this->assertSame([$this->cainta->id], $this->insights($manager)->visibleBranches()->pluck('id')->all());

        $this->actingAs($manager);
        Livewire::test(SalesOverview::class, ['pageFilters' => ['branch' => $this->sanMateo->id]])
            ->assertSee('₱6,000')
            ->assertDontSee('₱9,000');
    }

    public function test_a_super_admin_can_narrow_to_one_branch(): void
    {
        $this->fixture();
        $owner = User::factory()->owner()->create();

        $this->assertSame(9000, $this->insights($owner)->sales()['sales']);
        $this->assertSame(3000, $this->insights($owner, ['branch' => $this->sanMateo->id])->sales()['sales']);
        // A branch that doesn't exist is ignored
        $this->assertSame(9000, $this->insights($owner, ['branch' => 9999])->sales()['sales']);
        // The comparison table still shows every branch
        $this->assertCount(2, $this->insights($owner, ['branch' => $this->sanMateo->id])->branchTable());
    }

    // The page

    public function test_the_dashboard_page_has_period_and_branch_filters(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Dashboard::class)
            ->assertSuccessful()
            ->assertSchemaExists('filtersForm')
            ->assertFormFieldExists('period', 'filtersForm', fn ($field) => ! $field->isNative())
            ->assertFormFieldExists('branch', 'filtersForm', fn ($field) => ! $field->isNative())
            ->assertFormFieldHidden('from', 'filtersForm')
            ->set('filters.period', 'custom')
            ->assertFormFieldExists('from', 'filtersForm', fn ($field) => ! $field->isNative() && $field->isVisible())
            ->assertFormFieldExists('to', 'filtersForm', fn ($field) => ! $field->isNative() && $field->isVisible());
    }

    public function test_a_one_branch_user_gets_no_branch_filter(): void
    {
        $this->actingAs($this->staff('branch_manager'));

        Livewire::test(Dashboard::class)->assertFormFieldHidden('branch', 'filtersForm');
    }

    public function test_the_filters_are_kept_in_the_address(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->get($this->panel('?filters[period]=this_month'))->assertSuccessful();

        Livewire::withQueryParams(['filters' => ['period' => 'last_7']])
            ->test(Dashboard::class)
            ->assertSet('filters.period', 'last_7');
    }

    public function test_the_page_renders_for_every_role(): void
    {
        $this->fixture();

        foreach ([User::factory()->owner()->create(), $this->staff('branch_manager'), $this->staff('operations'), $this->staff('sales_admin')] as $user) {
            $this->actingAs($user)->get($this->dashboard())->assertSuccessful();
        }
    }

    // Export

    public function test_the_export_is_a_csv_of_the_daily_sales_for_the_period(): void
    {
        $this->fixture();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Dashboard::class)
            ->set('filters.period', 'custom')
            ->set('filters.from', '2026-11-09')
            ->set('filters.to', '2026-11-12')
            ->callAction('exportSales')
            ->assertFileDownloaded('826-daily-sales-2026-11-09-to-2026-11-12.csv', "Date,\"Units done\",Sales,Collected,Discounts\n2026-11-09,0,0,0,0\n2026-11-10,1,5000,5000,500\n2026-11-11,0,0,0,0\n2026-11-12,0,0,500,0\n");
    }

    public function test_the_export_follows_the_branch_filter(): void
    {
        $this->fixture();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Dashboard::class)
            ->set('filters.period', 'custom')
            ->set('filters.from', '2026-11-16')
            ->set('filters.to', '2026-11-16')
            ->set('filters.branch', $this->sanMateo->id)
            ->callAction('exportSales')
            ->assertFileDownloaded('826-daily-sales-2026-11-16-to-2026-11-16-san-mateo.csv', "Date,\"Units done\",Sales,Collected,Discounts\n2026-11-16,1,3000,0,0\n");
    }

    public function test_only_people_with_sales_can_export(): void
    {
        $this->actingAs($this->staff('operations'));

        Livewire::test(Dashboard::class)->assertActionHidden('exportSales');

        $this->actingAs($this->staff('sales_admin'));

        Livewire::test(Dashboard::class)->assertActionVisible('exportSales')->assertActionHasIcon('exportSales', Heroicon::OutlinedArrowDownTray);
    }

    // Queries

    public function test_the_queries_do_not_grow_with_the_number_of_bookings(): void
    {
        $owner = User::factory()->owner()->create();
        $run = function () use ($owner): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $insights = $this->insights($owner);
            $insights->attention();
            $insights->sales();
            $insights->daily();
            $insights->services();
            $insights->branchTable();
            $insights->turnaround();
            $insights->stages();
            $insights->utilization();
            $insights->customers();
            $insights->staff();

            return count(DB::getQueryLog());
        };

        $this->fixture();
        $this->job($this->cainta, '2026-11-02 12:00:00', 1000)->items()->create(['service_name' => 'Wash', 'price' => 1000, 'warranty_months' => 3]);
        $few = $run();

        foreach (range(1, 20) as $day) {
            $this->job($day % 2 ? $this->cainta : $this->sanMateo, '2026-11-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 12:00:00', 1000, ['guest_id' => $this->g1->id, 'arrived_at' => '2026-11-01 09:00:00'])
                ->items()->create(['service_name' => 'Wash', 'price' => 1000, 'warranty_months' => 3]);
        }

        $this->assertSame($few, $run());
    }
}
