<?php

namespace Tests\Feature;

use App\Filament\Pages\Queue;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The branch's queue: units booked for a day and units in the shop, moved
 * along by a card's button.
 */
class QueueTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, Nov 16, 2026
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->branch = Branch::factory()->cap(3)->create(['name' => 'Cainta']);
    }

    public function test_the_queue_shows_the_day_s_cap_and_the_units_by_step(): void
    {
        Booking::factory()->on('2026-11-17')->create(['branch_id' => $this->branch->id, 'vehicle_model' => 'Toyota Vios', 'name' => 'Booked Customer']);
        Booking::factory()->on('2026-11-17')->hold()->create(['branch_id' => $this->branch->id, 'name' => 'Unpaid Customer']);
        Booking::factory()->on('2026-11-14')->create(['branch_id' => $this->branch->id, 'status' => 'in_progress', 'name' => 'Working Customer']);
        Booking::factory()->on('2026-11-16')->create(['branch_id' => $this->branch->id, 'status' => 'ready', 'name' => 'Ready Customer']);
        Booking::factory()->on('2026-11-17')->create(['branch_id' => $this->branch->id, 'status' => 'cancelled', 'name' => 'Gone Away']);
        Booking::factory()->on('2026-11-10')->create(['branch_id' => $this->branch->id, 'status' => 'exited', 'name' => 'Already Left']);
        Booking::factory()->on('2026-11-18')->create(['branch_id' => $this->branch->id, 'name' => 'Another Day']);

        $this->actingAs(User::factory()->owner()->create())
            ->get($this->panel("/queue?branch={$this->branch->id}&date=2026-11-17"))
            ->assertOk()
            ->assertSee('Dropping off on Tuesday, Nov 17, 2026: 2 of 3')
            ->assertSee('1 place left')
            ->assertSee(['Booked', 'Arrived', 'In progress', 'Quality check', 'Ready'])
            ->assertSee('Booked Customer')
            ->assertSee('Unpaid Customer')
            ->assertSee('Waiting for the fee')
            ->assertSee('Working Customer')
            ->assertSee('Ready Customer')
            ->assertSee('Size needed')
            ->assertSee('Mark exited')
            ->assertDontSee('Gone Away')
            ->assertDontSee('Already Left')
            ->assertDontSee('Another Day');
    }

    public function test_a_full_or_closed_day_says_so_in_the_header(): void
    {
        Booking::factory()->count(3)->on('2026-11-17')->create(['branch_id' => $this->branch->id]);
        BranchBlock::factory()->between('2026-11-18 00:00', '2026-11-19 00:00')->create(['branch_id' => $this->branch->id]);

        $this->actingAs(User::factory()->owner()->create());

        $this->get($this->panel("/queue?branch={$this->branch->id}&date=2026-11-17"))->assertSee('3 of 3')->assertSee('Full');
        $this->get($this->panel("/queue?branch={$this->branch->id}&date=2026-11-18"))->assertSee('Closed day');
        $this->get($this->panel("/queue?branch={$this->branch->id}&date=2026-11-22"))->assertSee('Closed day');
    }

    public function test_a_card_s_button_moves_the_unit_to_the_next_step_and_records_the_times(): void
    {
        $booking = Booking::factory()->on('2026-11-16')->create(['branch_id' => $this->branch->id]);
        $this->actingAs(User::factory()->owner()->create());

        $page = Livewire::test(Queue::class, ['branch' => $this->branch->id])
            ->assertSee('Expected today')
            ->call('advance', $booking->id);

        $booking->refresh();
        $this->assertSame('arrived', $booking->status);
        $this->assertNotNull($booking->arrived_at);

        foreach (['in_progress', 'quality_check', 'ready', 'exited'] as $status) {
            $page->call('advance', $booking->id);
            $this->assertSame($status, $booking->refresh()->status);
        }

        $this->assertNotNull($booking->exited_at);

        // Nothing is left after exiting
        $page->call('advance', $booking->id);
        $this->assertSame('exited', $booking->refresh()->status);
    }

    public function test_staff_move_only_their_own_branch_s_units(): void
    {
        $other = Branch::factory()->create();
        $theirs = Booking::factory()->on('2026-11-16')->create(['branch_id' => $other->id]);
        $staff = User::factory()->create();
        $staff->branches()->attach($this->branch);

        $this->actingAs($staff);

        Livewire::test(Queue::class)->call('advance', $theirs->id)->assertNotFound();
        $this->assertSame('confirmed', $theirs->refresh()->status);
    }

    public function test_a_home_visit_goes_from_booked_to_in_progress_to_done(): void
    {
        $home = Booking::factory()->home()->create(['branch_id' => $this->branch->id, 'status' => 'confirmed', 'drop_off_on' => '2026-11-17', 'ready_on' => '2026-11-17']);

        $this->assertSame('in_progress', $home->nextStatus());
        $home->update(['status' => 'in_progress']);
        $this->assertSame('exited', $home->nextStatus());
    }
}
