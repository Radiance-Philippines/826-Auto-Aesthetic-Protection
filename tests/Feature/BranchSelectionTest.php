<?php

namespace Tests\Feature;

use App\Filament\Pages\Inbox;
use App\Filament\Pages\Queue;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\User;
use App\Support\CurrentBranch;
use App\Support\Live\Topics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The branch chosen in the admin scopes every list; "All" shows each person's branches (#695).
 */
class BranchSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $cainta;

    private Branch $sanMateo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cainta = Branch::factory()->create(['name' => 'Cainta']);
        $this->sanMateo = Branch::factory()->create(['name' => 'San Mateo']);
        Booking::factory()->create(['branch_id' => $this->cainta->id]);
        Booking::factory()->create(['branch_id' => $this->sanMateo->id]);
    }

    public function test_a_chosen_branch_scopes_lists_and_all_brings_them_back(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);

        $this->post(route('panel.branch'), ['branch' => $this->sanMateo->id])->assertRedirect()->assertSessionHas(CurrentBranch::SESSION, $this->sanMateo->id);

        $this->assertSame([$this->sanMateo->id], BookingResource::getEloquentQuery()->pluck('branch_id')->all());
        $this->assertSame([$this->sanMateo->id], Booking::query()->visibleTo($owner)->pluck('branch_id')->all());
        $this->assertSame([$this->sanMateo->id], array_keys(BookingResource::branchOptions()));
        $this->assertSame(0, Guest::query()->visibleTo($owner)->whereHas('bookings', fn ($query) => $query->where('branch_id', $this->cainta->id))->count());
        $this->assertNotContains(Topics::queue($this->cainta->id), Topics::forUser($owner));

        $this->post(route('panel.branch'), ['branch' => ''])->assertSessionMissing(CurrentBranch::SESSION);

        $this->assertSame(2, BookingResource::getEloquentQuery()->count());
    }

    public function test_nobody_can_choose_a_branch_that_is_not_theirs(): void
    {
        $manager = User::factory()->branchManager()->create();
        $manager->branches()->sync([$this->cainta->id]);

        $this->actingAs($manager)->post(route('panel.branch'), ['branch' => $this->sanMateo->id])->assertForbidden();

        // A stale choice (the branch was taken away) is ignored, never obeyed
        session([CurrentBranch::SESSION => $this->sanMateo->id]);
        $this->assertSame([$this->cainta->id], $manager->scopedBranchIds());
    }

    public function test_one_persons_choice_does_not_narrow_anyone_else(): void
    {
        $owner = User::factory()->owner()->create();
        $other = User::factory()->owner()->create();
        $this->actingAs($owner);
        CurrentBranch::choose($owner, $this->cainta->id);

        $this->assertNull($other->scopedBranchIds());
    }

    public function test_the_inbox_has_branch_tabs_only_on_all(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);

        Livewire::test(Inbox::class)->assertSeeHtml('aria-label="Branches"');

        CurrentBranch::choose($owner, $this->cainta->id);

        Livewire::test(Inbox::class)->assertDontSeeHtml('aria-label="Branches"')->assertSet('branch', (string) $this->cainta->id);
    }

    public function test_the_queue_follows_the_choice_over_the_address(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        CurrentBranch::choose($owner, $this->sanMateo->id);

        Livewire::withQueryParams(['branch' => $this->cainta->id])->test(Queue::class)->assertSet('branch', $this->sanMateo->id);
    }
}
