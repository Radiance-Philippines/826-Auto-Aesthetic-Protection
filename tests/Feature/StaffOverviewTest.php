<?php

namespace Tests\Feature;

use App\Models\Backjob;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Branch;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The staff app's Overview tab: the dashboard's numbers, scoped to the
 * person's branches, and only the cards their role sees on the website.
 */
class StaffOverviewTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private Branch $cainta;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00', 'Asia/Manila'));
        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);

        $this->finished($this->cainta, 2500, now(), ['amount_due' => 500, 'paid_at' => now()]);
        $this->finished($this->cainta, 3500, now());
        $this->finished($this->cainta, 4000, now()->subDays(3));
        $this->finished($this->sanMateo, 1000, now());
        Review::factory()->approved()->create(['branch_id' => $this->cainta->id, 'rating' => 4]);
        Review::factory()->approved()->create(['branch_id' => $this->cainta->id, 'rating' => 5]);
        Review::factory()->approved()->create(['branch_id' => $this->sanMateo->id, 'rating' => 1]);
        Review::factory()->create(['branch_id' => $this->cainta->id, 'rating' => 1]);
    }

    private function finished(Branch $branch, int $price, mixed $exitedAt, array $state = []): Booking
    {
        return Booking::factory()->priced($price)->create([
            'branch_id' => $branch->id,
            'status' => 'exited',
            'arrived_at' => $exitedAt->copy()->subDays(2),
            'ready_at' => $exitedAt->copy()->subDay(),
            'exited_at' => $exitedAt,
            'drop_off_on' => $exitedAt->copy()->subDays(2),
            ...$state,
        ]);
    }

    private function backjob(Branch $branch, string $status = 'open'): Backjob
    {
        $booking = $this->finished($branch, 100, now()->subDays(40));

        return Backjob::create(['booking_id' => $booking->id, 'branch_id' => $branch->id, 'noted_on' => today(), 'status' => $status]);
    }

    public function test_a_branch_manager_sees_the_branch_numbers_and_the_team(): void
    {
        $manager = $this->staffUser('branch_manager', $this->cainta);
        $this->backjob($this->cainta);
        $this->backjob($this->cainta, 'done');
        $this->backjob($this->sanMateo);
        BookingEvent::create(['booking_id' => Booking::where('branch_id', $this->cainta->id)->first()->id, 'user_id' => $manager->id, 'kind' => 'status', 'from' => 'Ready', 'to' => 'Exited']);

        $response = $this->example('overview', 'GET /api/staff/overview?period=today|7d|30d|month&branch=<id>', $this->getJson('/api/staff/overview?period=today', $this->bearer($manager)))->assertOk();

        $response->assertJsonPath('period.key', 'today')
            ->assertJsonPath('period.from', '2026-11-16')
            ->assertJsonPath('period.to', '2026-11-16')
            ->assertJsonPath('branch', null)
            ->assertJsonPath('branches', [['id' => $this->cainta->id, 'name' => 'Cainta']])
            ->assertJsonPath('sales', 6000)
            ->assertJsonPath('collected', 500)
            ->assertJsonPath('averageTicket', 3000)
            ->assertJsonPath('unitsDone', 2)
            ->assertJsonPath('turnaround', ['toReady' => 1, 'toExit' => 2])
            // One branch: no branches table; backjobs and reviews are this branch's
            ->assertJsonPath('branchesTable', null)
            ->assertJsonPath('backjobsOpen', 1)
            ->assertJsonPath('reviewRating', ['rating' => 4.5, 'count' => 2])
            ->assertJsonPath('topReps.0.name', $manager->name)
            ->assertJsonPath('topReps.0.moves', 1);
        $this->assertArrayHasKey('attention', $response->json());
        $this->assertStringNotContainsString('1000', $response->getContent());
    }

    public function test_periods_cover_the_dashboards_ranges(): void
    {
        $manager = $this->staffUser('branch_manager', $this->cainta);
        $sales = fn (string $period) => $this->getJson("/api/staff/overview?period={$period}", $this->bearer($manager))->json('sales');

        $this->assertSame(6000, $sales('today'));
        $this->assertSame(10000, $sales('7d'));
        $this->assertSame(10000, $sales('30d'));
        $this->assertSame(10000, $sales('month'));
        // No period: the last 7 days
        $this->getJson('/api/staff/overview', $this->bearer($manager))->assertJsonPath('period.key', '7d')->assertJsonPath('sales', 10000);
        $this->getJson('/api/staff/overview?period=year', $this->bearer($manager))->assertUnprocessable();
    }

    public function test_two_branches_get_the_branches_table_and_a_branch_filter(): void
    {
        $manager = $this->staffUser('branch_manager', $this->cainta);
        $manager->branches()->attach($this->sanMateo);
        $headers = $this->bearer($manager);

        $all = $this->getJson('/api/staff/overview?period=today', $headers)->assertOk();
        $all->assertJsonPath('sales', 7000)->assertJsonCount(2, 'branchesTable')->assertJsonPath('branchesTable.0.name', 'Cainta')->assertJsonPath('branchesTable.0.sales', 6000)->assertJsonPath('branchesTable.1.name', 'San Mateo')->assertJsonCount(2, 'branches');

        // The branch filter narrows the numbers, not the table
        $one = $this->getJson("/api/staff/overview?period=today&branch={$this->sanMateo->id}", $headers)->assertOk();
        $one->assertJsonPath('sales', 1000)->assertJsonPath('branch.name', 'San Mateo')->assertJsonCount(2, 'branchesTable')->assertJsonPath('reviewRating', ['rating' => 1, 'count' => 1]);
    }

    public function test_another_branch_s_numbers_are_a_404_and_a_super_admin_sees_everything(): void
    {
        $manager = $this->staffUser('branch_manager', $this->cainta);

        $this->getJson("/api/staff/overview?branch={$this->sanMateo->id}", $this->bearer($manager))->assertNotFound();

        $owner = $this->getJson('/api/staff/overview?period=today', $this->bearer(User::factory()->owner()->create()))->assertOk();
        $owner->assertJsonPath('sales', 7000)->assertJsonPath('reviewRating.count', 3);
        $this->assertContains('San Mateo', collect($owner->json('branchesTable'))->pluck('name')->all());
    }

    public function test_the_detailer_gets_no_money_no_team_no_reviews(): void
    {
        $operations = $this->staffUser('operations', $this->cainta);
        $this->backjob($this->cainta);

        $response = $this->example('overview-detailer', 'GET /api/staff/overview (operations: counts only)', $this->getJson('/api/staff/overview?period=today', $this->bearer($operations)))->assertOk();

        $response->assertJsonPath('sales', null)
            ->assertJsonPath('collected', null)
            ->assertJsonPath('averageTicket', null)
            ->assertJsonPath('branchesTable', null)
            ->assertJsonPath('topReps', null)
            ->assertJsonPath('reviewRating', null)
            ->assertJsonPath('unitsDone', 2)
            ->assertJsonPath('turnaround.toExit', 2)
            ->assertJsonPath('backjobsOpen', 1);
        foreach (['6000', '2500', '3500', '3000'] as $money) {
            $this->assertStringNotContainsString($money, $response->getContent());
        }
    }

    public function test_sales_admin_sees_money_and_reviews_but_not_the_team(): void
    {
        $sales = $this->staffUser('sales_admin', $this->cainta);

        $this->getJson('/api/staff/overview?period=today', $this->bearer($sales))
            ->assertOk()
            ->assertJsonPath('sales', 6000)
            ->assertJsonPath('topReps', null)
            ->assertJsonPath('turnaround', null)
            ->assertJsonPath('reviewRating.count', 2);
    }

    public function test_no_dashboard_area_is_a_403(): void
    {
        $nothing = $this->staffUser('branch_manager', $this->cainta, ['permissions' => ['bookings.view']]);

        $this->getJson('/api/staff/overview', $this->bearer($nothing))->assertForbidden();
    }
}
