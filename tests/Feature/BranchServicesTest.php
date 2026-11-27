<?php

namespace Tests\Feature;

use App\Filament\Pages\BranchServices;
use App\Models\Branch;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A branch manager chooses which services their own branch offers, and nothing else.
 */
class BranchServicesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $mine;

    private Branch $other;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->mine = Branch::where('slug', 'cainta')->sole();
        $this->other = Branch::where('slug', 'san-mateo')->sole();
        $this->manager = User::factory()->branchManager()->create();
        $this->manager->branches()->sync([$this->mine->id]);
    }

    public function test_a_manager_ticks_services_for_their_branch_and_hidden_ones_stay(): void
    {
        [$first, $second, $hidden] = Service::query()->orderBy('id')->take(3)->get();
        $hidden->update(['is_active' => false]);
        $this->mine->services()->sync([$first->id, $hidden->id]);

        $this->actingAs($this->manager)->post(route('panel.branch.services', $this->mine), ['services' => [$second->id]])->assertRedirect();

        $this->assertEqualsCanonicalizing([$second->id, $hidden->id], $this->mine->services()->pluck('services.id')->all());

        Livewire::test(BranchServices::class)->assertSee($this->mine->name)->assertDontSee($this->other->name);
    }

    public function test_not_another_branch_and_not_without_the_area(): void
    {
        $this->actingAs($this->manager)->post(route('panel.branch.services', $this->other), ['services' => []])->assertForbidden();

        $detailer = User::factory()->operations()->create();
        $detailer->branches()->sync([$this->mine->id]);

        $this->actingAs($detailer)->post(route('panel.branch.services', $this->mine), ['services' => []])->assertForbidden();
        $this->assertFalse(BranchServices::canAccess());
    }
}
